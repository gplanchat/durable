<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Runs workflows on an in-memory stack while reproducing suspension.
 *
 * Simulates the distributed flow: on every await() over an activity that has not
 * completed, the workflow suspends, the "worker" runs the activities in the queue,
 * then the workflow resumes (replay). Lets suspension behaviour be tested without
 * external processes or Messenger.
 */
final class InMemoryWorkflowRunner
{
    public const DEFAULT_BUDGET_SECONDS = 10.0;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        /**
         * Required to run child workflows: without a registry no child type can be resolved
         * and {@see \Gplanchat\Durable\ExecutionContext::executeChildWorkflow()} throws.
         */
        private readonly ?WorkflowRegistry $workflowRegistry = null,
        /**
         * Total budget of an execution. Activity attempts being unlimited by default
         * (Temporal semantics), an inline harness needs a bound: without one, an activity
         * that keeps failing would spin this runner forever.
         */
        private readonly float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ) {}

    /**
     * Starts a workflow and loops suspend/resume until completion.
     *
     * @return mixed the handler's result
     */
    public function run(string $executionId, callable $handler): mixed
    {
        // Virtual clock: an inline harness has nobody to deliver a timer wake-up, and waiting
        // out a due time for real would make every workflow that sleeps untestable. It only
        // moves from one due time to the next, never on its own.
        // An object, not a variable: an arrow function captures by value, and the clock would
        // never move.
        $clock = new class {
            public float $now;
        };
        $clock->now = microtime(true);

        $runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $this->maxActivityRetries,
            static fn(): float => $clock->now,
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
                )
                : null,
            new ParentChildWorkflowCoordinator($this->eventStore),
        );

        // What the last suspension was waiting on, when that has a name: it is all that
        // separates "stuck" from "stuck on that particular condition" in the diagnosis.
        $waitingOn = null;

        try {
            return $engine->start($executionId, $handler);
        } catch (WorkflowSuspendedException $e) {
            // DUR003: expected suspension (control flow), not an error — the while loop runs the worker then resumes.
            $waitingOn = $e->waitingOn();
        }

        $deadline = microtime(true) + $this->budgetSeconds;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw WorkflowStuckException::budgetExhausted($executionId, $this->budgetSeconds);
            }

            $before = $this->eventStore->countEventsInStream($executionId);
            $this->runActivityWorker($executionId, $runtime, max(0.0, $deadline - microtime(true)));
            // Timers already due fire on every round; time itself does not move yet.
            $runtime->checkTimers($this->timerContext($executionId, $runtime));

            try {
                return $engine->resume($executionId, $handler);
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
            if ($this->eventStore->countEventsInStream($executionId) === $before) {
                // Nothing moves any more: only now are we allowed to move time forward. Doing
                // it sooner would hand the timer a race the activity was in the middle of
                // winning.
                if ($this->skipToNextTimer($executionId, $runtime, $clock)) {
                    continue;
                }

                // An attempt still queued tells the two causes apart: the workflow is still
                // retrying (budget exhausted), rather than waiting for an event that will not come.
                throw null !== $this->activityTransport->nextDueAt()
                    ? WorkflowStuckException::budgetExhausted($executionId, $this->budgetSeconds)
                    : WorkflowStuckException::noProgress($executionId, $waitingOn);
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
    private function skipToNextTimer(string $executionId, ExecutionRuntime $runtime, object $clock): bool
    {
        $dueInMs = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($this->eventStore, $executionId, $clock->now);
        if (null === $dueInMs) {
            return false;
        }

        $clock->now += max(0.0, (float) $dueInMs / 1000.0);
        $runtime->checkTimers($this->timerContext($executionId, $runtime));

        return true;
    }

    private function timerContext(string $executionId, ExecutionRuntime $runtime): ExecutionContext
    {
        return new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $executionId, $runtime->nowSeconds(...)),
        );
    }

    private function runActivityWorker(string $executionId, ExecutionRuntime $runtime, float $budgetSeconds): void
    {
        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $executionId, $runtime->nowSeconds(...)),
            null,
        );
        $runtime->runUntilIdle($context, $budgetSeconds);
    }
}
