<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\PendingTimers;
use Gplanchat\Durable\Timer\VirtualClock;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Psr\Clock\ClockInterface;

/**
 * The Symfony bundle always registers suspension on an unresolved await (6th argument set to true).
 * Tests can pass false to simulate a synchronous drain within the same process.
 */
final class ExecutionRuntime
{
    private readonly ClockInterface $clock;

    private ?ActivityMessageProcessor $activityMessageProcessor = null;

    /**
     * Time budget of the synchronous drain: this is an inline harness, not a worker — it cannot
     * sleep forever on the backoff of an activity that always fails.
     */
    public const DEFAULT_DRAIN_BUDGET_SECONDS = 5.0;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        ?ClockInterface $clock = null,
        private readonly bool $distributed = false,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param Awaitable<mixed> $awaitable
     */
    public function await(Awaitable $awaitable, ExecutionContext $context): mixed
    {
        if ($awaitable->isSettled()) {
            return $awaitable->getResult();
        }

        if ($this->distributed) {
            if (null !== \Fiber::getCurrent()) {
                \Fiber::suspend($awaitable);

                // Resumed by ExecutionEngine fiber loop after the awaitable was settled
                return $awaitable->getResult();
            }

            // Every engine pass runs the workflow in a fiber: an await outside one would pass for a
            // suspension that nothing ever resumes (#329).
            throw new \LogicException(\sprintf('Workflow %s awaited outside a fiber in distributed mode: run it through ExecutionEngine.', $context->executionId()));
        }

        // Synchronous in-memory drain (distributed=false)
        while (!$awaitable->isSettled()) {
            $this->drainActivityQueueOnce($context);
            $this->checkTimers($context);
        }

        return $awaitable->getResult();
    }

    /**
     * @param EventStoreInterface|null $journal the pass's journal when a pass fires the timers
     *                                          (DUR053); the runtime's own store otherwise
     */
    public function checkTimers(ExecutionContext $context, ?EventStoreInterface $journal = null): void
    {
        $journal ??= $this->eventStore;
        foreach (PendingTimers::dueAt($journal, $context->executionId(), $this->nowSeconds()) as $timerId) {
            $journal->append(new TimerCompleted(ExecutionId::fromString($context->executionId()), $timerId));
            $context->resolveTimer($timerId);
        }
    }

    /**
     * Clock used by {@see checkTimers()} and by the Messenger delay computation for timers.
     */
    public function nowSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /**
     * The clock itself, for what this runtime builds around a pass (the command buffer).
     */
    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    /**
     * Runs **one** activity attempt inline, then settles the context's awaitable.
     *
     * The work itself is delegated to {@see ActivityMessageProcessor}, the same one the
     * Messenger worker uses: timeouts, worker markers, retry policy and heartbeat cancellation
     * used to be absent from this path, so that the public test harness
     * ({@see \Gplanchat\Durable\Testing\WorkflowTestEnvironment}) did not reproduce
     * production behaviour. Only settling the awaitable stays here: it exists only in the
     * inline drain, where the workflow fiber lives in the same process.
     *
     * @return bool false when the queue had no ready message
     */
    public function drainActivityQueueOnce(ExecutionContext $context): bool
    {
        $message = $this->activityTransport->dequeue();
        if (null === $message) {
            return false;
        }

        $this->activityMessageProcessor()->process($message);

        $outcome = ActivityEventJournal::lastTerminalOutcome(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
        );

        switch (true) {
            case $outcome instanceof ActivityCompleted:
                $context->resolveActivity($message->activityId, $outcome->result());
                break;
            case $outcome instanceof ActivityFailed:
                $context->rejectActivity($message->activityId, DurableActivityFailedException::toThrowable($outcome));
                break;
            case $outcome instanceof ActivityCatastrophicFailure:
                $context->rejectActivity($message->activityId, new DurableCatastrophicActivityFailureException($outcome));
                break;
            case $outcome instanceof ActivityCancelled:
                $context->rejectActivity($message->activityId, new ActivitySupersededException($message->activityId, $outcome->reason()));
                break;
            default:
                // No terminal outcome: a retry is queued, it will be handled on the next round.
                break;
        }

        return true;
    }

    private function activityMessageProcessor(): ActivityMessageProcessor
    {
        return $this->activityMessageProcessor ??= new ActivityMessageProcessor(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            new NullWorkflowResumeDispatcher(),
            new NullActivityHeartbeatSender(),
            $this->maxActivityRetries,
            $this->workflowExecutionObserver,
            clock: $this->clock,
        );
    }

    /**
     * Drains the queue until it is exhausted, **deferred retries included**.
     *
     * `isEmpty()` only reports the absence of a *ready* message: looping on it concluded
     * "nothing left to do" while a retry was scheduled a few seconds later, so that the retry
     * policy did not apply at all in the test harness.
     *
     * ponytail: the backoff is waited out for real — this drain is synchronous and in the same
     * process. A virtual clock shared with the transport would allow moving it forward.
     */
    /**
     * @param ClockInterface|null $queueClock the clock the transport stamps its due times with,
     *                                        when it is not this runtime's (a harness that skips
     *                                        time on the runtime, not on the queue)
     * @param VirtualClock|null   $waitedOn   a harness's virtual clock, moved by the real time
     *                                        spent here waiting out a backoff: that wait counts
     *                                        towards the schedule-to-* bounds
     */
    public function runUntilIdle(ExecutionContext $context, ?float $budgetSeconds = null, ?ClockInterface $queueClock = null, ?VirtualClock $waitedOn = null): void
    {
        $queueClock ??= $this->clock;
        // The budget is a length of real waiting (usleep below): measured on the monotonic timer.
        $budgetEndsAt = hrtime(true) + (int) (($budgetSeconds ?? self::DEFAULT_DRAIN_BUDGET_SECONDS) * 1e9);
        $deadline = (float) $queueClock->now()->format('U.u') + ($budgetSeconds ?? self::DEFAULT_DRAIN_BUDGET_SECONDS);

        while (null !== ($dueAt = $this->activityTransport->nextDueAt())) {
            // Attempts are unlimited by default (Temporal semantics): an activity that keeps
            // failing would spin this drain forever. In production the Messenger transport
            // hands control back between two attempts; here we stop, and the caller reports an
            // execution that is no longer moving.
            if ($dueAt > $deadline || hrtime(true) >= $budgetEndsAt) {
                return;
            }

            $wait = $dueAt - (float) $queueClock->now()->format('U.u');
            if ($wait > 0) {
                // A timer that falls due during the backoff fires before the retry, as on Temporal:
                // wait only until then and hand back, so the caller fires it and resumes (#653).
                // An attempt itself is never cut short: the virtual clock still does not move
                // while an activity runs.
                $timerWait = null !== $waitedOn ? $this->secondsUntilNextTimer($context, $waitedOn) : null;
                $timerFirst = null !== $timerWait && $timerWait < $wait;
                $waitStartedAt = hrtime(true);
                usleep((int) ceil(($timerFirst ? $timerWait : $wait) * 1_000_000.0));
                $waitedOn?->advance(((float) (hrtime(true) - $waitStartedAt)) / 1e9);
                if ($timerFirst) {
                    return;
                }
            }
            if (!$this->drainActivityQueueOnce($context)) {
                return;
            }
        }
    }

    /**
     * Seconds until the context's next pending timer falls due on the virtual clock, or null.
     */
    private function secondsUntilNextTimer(ExecutionContext $context, VirtualClock $clock): ?float
    {
        $pending = PendingTimers::of($this->eventStore, $context->executionId());

        return [] === $pending ? null : max(0.0, min($pending) - $clock->seconds());
    }

    public function getActivityTransport(): ActivityTransportInterface
    {
        return $this->activityTransport;
    }
}
