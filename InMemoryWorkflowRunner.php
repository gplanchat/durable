<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\ContinuationCapReachedException;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Timer\VirtualClock;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Psr\Clock\ClockInterface;

/**
 * Runs workflows on an in-memory stack while reproducing suspension.
 *
 * Simulates the distributed flow: on every await() over an activity that has not
 * completed, the workflow suspends, the "worker" runs the activities in the queue,
 * then the workflow resumes (replay). Lets suspension behaviour be tested without
 * external processes or Messenger.
 */
final readonly class InMemoryWorkflowRunner
{
    public const DEFAULT_BUDGET_SECONDS = 10.0;
    public const DEFAULT_MAX_CONTINUATIONS = 10;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        /**
         * Required to run child workflows: without a registry no child type can be resolved
         * and {@see \Gplanchat\Durable\ExecutionContext::executeChildWorkflow()} throws.
         * Without one, a continue-as-new still reaches the caller as ContinueAsNewRequested.
         */
        private readonly ?WorkflowRegistry $workflowRegistry = null,
        /**
         * Total budget of an execution. Activity attempts being unlimited by default
         * (Temporal semantics), an inline harness needs a bound: without one, an activity
         * that keeps failing would spin this runner forever.
         */
        private readonly float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
        /**
         * The clock the activity queue runs on, and where the virtual time starts unless
         * `$virtualTimeStartsAt` says otherwise: it must be the clock `$activityTransport` stamps
         * its due times with. Handed a different one, the drain never sees a delayed retry fall
         * due and the run ends as budget exhausted.
         */
        ?ClockInterface $clock = null,
        /**
         * Where the virtual time starts, when it is not the queue's clock: an inline child starts
         * at its parent's virtual now, while its queue keeps the transport's clock (#652).
         */
        private readonly ?ClockInterface $virtualTimeStartsAt = null,
        /**
         * How many times a chain may continue as new before the run fails. The budget does not
         * bound a chain, since each run gets its own: a workflow that always continues as new
         * would run forever (#888). `0` allows no continuation; a negative value throws.
         */
        private readonly int $maxContinuations = self::DEFAULT_MAX_CONTINUATIONS,
    ) {
        if ($maxContinuations < 0) {
            throw new \InvalidArgumentException(\sprintf('maxContinuations must be 0 or more, %d given.', $maxContinuations));
        }
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Starts a workflow and loops suspend/resume until completion.
     *
     * @return mixed the handler's result
     */
    public function run(ExecutionId $executionId, callable $handler, ?string $workflowType = null): mixed
    {
        $startedExtras = [];
        $firstExecutionId = $executionId;
        $continuations = 0;

        // A continue-as-new chain is followed to its last run, as ResumeWorkflowHandler does on the
        // journal backends (#802); each run gets its own budget.
        while (true) {
            try {
                return $this->runOnce($executionId, $handler, $workflowType, $startedExtras);
            } catch (ContinueAsNewRequested $e) {
                if (null === $this->workflowRegistry || null === $e->nextExecutionId) {
                    throw $e;
                }
                if (++$continuations > $this->maxContinuations) {
                    throw new ContinuationCapReachedException($firstExecutionId->toString(), $this->maxContinuations);
                }
                $startedExtras = ['continuedFromExecutionId' => $executionId->toString()];
                $executionId = $e->nextExecutionId;
                // The alias, as ResumeWorkflowHandler journals it; the registry knows both keys.
                $workflowType = (new WorkflowDefinitionLoader())->aliasForTemporalInterop($e->workflowType);
                $handler = $this->workflowRegistry->getHandler($e->workflowType, $e->payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $startedExtras merged into the run's ExecutionStarted
     */
    private function runOnce(ExecutionId $id, callable $handler, ?string $workflowType, array $startedExtras): mixed
    {
        // Virtual clock: an inline harness has nobody to deliver a timer wake-up, and waiting
        // out a due time for real would make every workflow that sleeps untestable. It only
        // moves from one due time to the next, never on its own.
        // An object, not a variable: an arrow function captures by value, and the clock would
        // never move.
        $clock = new VirtualClock((float) ($this->virtualTimeStartsAt ?? $this->clock)->now()->format('U.u'));

        $runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $this->maxActivityRetries,
            $clock,
            true, // distributed = true => suspension
        );
        // The engine was built without a child runner or a parent/child coordinator:
        // a workflow with children threw a LogicException and ParentClosePolicy never
        // cascaded — two production behaviours missing from the test harness.
        $engine = new ExecutionEngine(
            $this->eventStore,
            $runtime,
            null !== $this->workflowRegistry
                ? new ChildWorkflowRunner(
                    $this->eventStore,
                    $runtime,
                    $this->workflowRegistry,
                    $this->activityExecutor,
                    $this->maxActivityRetries,
                    queueClock: $this->clock,
                )
                : null,
            new ParentChildWorkflowCoordinator($this->eventStore),
        );

        // What the last suspension was waiting on, when that has a name: it is all that
        // separates "stuck" from "stuck on that particular condition" in the diagnosis.
        try {
            return $engine->start($id, $handler, $workflowType, $startedExtras);
        } catch (WorkflowSuspendedException $e) {
            // DUR003: expected suspension (control flow), not an error — the while loop runs the worker then resumes.
            $waitingOn = $e->waitingOn();
        }

        // A length of real work, not an instant: the monotonic timer, which the virtual clock is not.
        $deadline = hrtime(true) + (int) ($this->budgetSeconds * 1e9);

        while (true) {
            if (hrtime(true) >= $deadline) {
                throw WorkflowStuckException::budgetExhausted($id->toString(), $this->budgetSeconds);
            }

            $before = $this->eventStore->countEventsInStream($id);
            $this->runActivityWorker($id, $runtime, $clock, max(0.0, ((float) ($deadline - hrtime(true))) / 1e9));
            // Timers already due fire on every round; time itself does not move yet.
            $runtime->checkTimers($this->timerContext($id, $runtime), PassEventStore::open($this->eventStore, $id));

            try {
                return $engine->resume($id, $handler);
            } catch (WorkflowSuspendedException $e) {
                // DUR003: same — suspension until activities have produced the events needed for replay.
                $waitingOn = $e->waitingOn();
            }

            // A round that adds nothing to the log cannot add anything on the next one: the
            // workflow is waiting for something this runner will never produce (an undelivered
            // signal, an update, a distant timer). Without this guard the loop spun empty
            // forever — a test that forgets to deliver its signal froze everything after it.
            // ponytail: detection by absence of progress; a real timer scheduler would call
            // for a virtual clock.
            if ($this->eventStore->countEventsInStream($id) === $before) {
                // Nothing moves any more: only now are we allowed to move time forward. Doing
                // it sooner would hand the timer a race the activity was in the middle of
                // winning.
                if ($this->skipToNextTimer($id, $runtime, $clock)) {
                    continue;
                }

                // An attempt still queued tells the two causes apart: the workflow is still
                // retrying (budget exhausted), rather than waiting for an event that will not come.
                throw null !== $this->activityTransport->nextDueAt()
                    ? WorkflowStuckException::budgetExhausted($id->toString(), $this->budgetSeconds)
                    : WorkflowStuckException::noProgress($id->toString(), $waitingOn);
            }
        }
    }

    /**
     * Moves the virtual clock to the next due time and fires the timer.
     *
     * This is what makes `sleep(3600)` testable in a millisecond, without burning real time.
     * In production the worker does the opposite: it waits for the wake-up scheduled for it by
     * {@see \Gplanchat\Durable\Timer\TimerWakeDelayCalculator}.
     *
     * Only called when nothing else is making progress — skipping time while an activity can
     * still succeed would hand the timer every `any(activity, timer)`.
     *
     * @return bool true when time was moved forward
     */
    private function skipToNextTimer(ExecutionId $id, ExecutionRuntime $runtime, VirtualClock $clock): bool
    {
        $dueInMs = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($this->eventStore, $id->toString(), $clock->seconds());
        if (null === $dueInMs) {
            return false;
        }

        $clock->advance((float) $dueInMs / 1000.0);
        $runtime->checkTimers($this->timerContext($id, $runtime), PassEventStore::open($this->eventStore, $id));

        return true;
    }

    private function timerContext(ExecutionId $id, ExecutionRuntime $runtime): ExecutionContext
    {
        return new ExecutionContext(
            $id,
            $history = new EventStoreHistorySource($this->eventStore, $id),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $id, $runtime->clock(), $history),
        );
    }

    private function runActivityWorker(ExecutionId $id, ExecutionRuntime $runtime, VirtualClock $clock, float $budgetSeconds): void
    {
        $context = new ExecutionContext(
            $id,
            $history = new EventStoreHistorySource($this->eventStore, $id),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $id, $runtime->clock(), $history),
            null,
        );
        $runtime->runUntilIdle($context, $budgetSeconds, $this->clock, $clock);
    }
}
