<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;

/**
 * The Symfony bundle always registers suspension on an unresolved await (6th argument set to true).
 * Tests can pass false to simulate a synchronous drain within the same process.
 */
final class ExecutionRuntime
{
    /** @var callable(): float */
    private $clock;

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
        ?callable $clock = null,
        private readonly bool $distributed = false,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
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

            // Called outside of a fiber (backward-compatibility path for non-fiber callers)
            throw new WorkflowSuspendedException(\sprintf('Workflow %s suspended (distributed mode)', $context->executionId()), 0, null, $this->awaitableShouldDispatchResume($awaitable), AwaitableInspector::waitsOnTimer($awaitable));
        }

        // Synchronous in-memory drain (distributed=false)
        while (!$awaitable->isSettled()) {
            $this->drainActivityQueueOnce($context);
            $this->checkTimers($context);
        }

        return $awaitable->getResult();
    }

    public function checkTimers(ExecutionContext $context): void
    {
        $now = ($this->clock)();
        $scheduledIds = [];
        $completedIds = [];
        $cancelledIds = [];
        foreach ($this->eventStore->readStream($context->executionId()) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = ['id' => $event->timerId(), 'at' => $event->scheduledAt()];
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
            if ($event instanceof TimerCancelled) {
                $cancelledIds[$event->timerId()] = true;
            }
        }

        foreach ($scheduledIds as $info) {
            if (isset($completedIds[$info['id']]) || isset($cancelledIds[$info['id']])) {
                continue;
            }
            if ($now >= $info['at']) {
                $this->eventStore->append(new TimerCompleted($context->executionId(), $info['id']));
                $completedIds[$info['id']] = true;
                $context->resolveTimer($info['id']);
            }
        }
    }

    /**
     * Clock used by {@see checkTimers()} and by the Messenger delay computation for timers.
     */
    public function nowSeconds(): float
    {
        return ($this->clock)();
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
    public function runUntilIdle(ExecutionContext $context, ?float $budgetSeconds = null): void
    {
        $deadline = microtime(true) + ($budgetSeconds ?? self::DEFAULT_DRAIN_BUDGET_SECONDS);

        while (null !== ($dueAt = $this->activityTransport->nextDueAt())) {
            // Attempts are unlimited by default (Temporal semantics): an activity that keeps
            // failing would spin this drain forever. In production the Messenger transport
            // hands control back between two attempts; here we stop, and the caller reports an
            // execution that is no longer moving.
            if ($dueAt > $deadline || microtime(true) >= $deadline) {
                return;
            }

            $wait = $dueAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000.0));
            }
            if (!$this->drainActivityQueueOnce($context)) {
                return;
            }
        }
    }

    public function getActivityTransport(): ActivityTransportInterface
    {
        return $this->activityTransport;
    }

    /**
     * Timer: {@see ResumeWorkflowHandler} sends {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage} (not a direct resume).
     * Activity: false — {@see ActivityMessageProcessor} calls {@see \Gplanchat\Durable\Port\WorkflowResumeDispatcher::dispatchResume}
     * at the end of the activity; a {@code dispatchResume} from the workflow handler with a **sync/in-memory** transport would loop forever.
     * Signal / update: only {@see DeliverWorkflowSignalHandler} and friends must trigger a resume.
     *
     * @param Awaitable<mixed> $awaitable
     */
    private function awaitableShouldDispatchResume(Awaitable $awaitable): bool
    {
        return AwaitableInspector::waitsOnTimer($awaitable);
    }
}
