<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Store\WorkflowTaskJournal;
use Gplanchat\Durable\Timer\PendingChildDeadlines;
use Gplanchat\Durable\Timer\PendingTimers;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;

/**
 * Cron / message: advances a run's timers, then resumes it if needed.
 *
 * If no timers fire on this pass (because the transport delivered the message before
 * the scheduled time elapsed — typically the in-memory transport in tests ignores
 * DelayStamp), the handler re-dispatches the check message with a fresh delay so
 * the workflow eventually resumes once the timer actually expires.
 */
final readonly class FireWorkflowTimersHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly WorkflowTimerDispatcher $timerDispatcher,
    ) {}

    public function __invoke(FireWorkflowTimersMessage $message): void
    {
        // Firing timers is a pass: it claims the execution, and a newer pass supersedes it (DUR053).
        $id = ExecutionId::fromString($message->executionId);
        $journal = PassEventStore::open($this->eventStore, $id);
        $context = new ExecutionContext(
            $id,
            $history = new EventStoreHistorySource($journal, $id),
            new EventStoreCommandBuffer($journal, $this->runtime->getActivityTransport(), $id, $this->runtime->clock(), $history),
            null,
        );

        // DUR052 §5: the due timers are named before they fire. None due, nothing is announced.
        $due = PendingTimers::dueAt($this->eventStore, $id, $this->runtime->nowSeconds());
        if ([] !== $due) {
            $this->resumeDispatcher->dispatchResumeAwaiting($id, AwaitedFact::timers($due));
        }

        $before = $this->countTimerCompleted($id);

        try {
            $this->runtime->checkTimers($context, $journal);
            $timedOut = $this->failChildrenPastTheirDeadline($id, $journal);
        } catch (SupersededPassException) {
            return; // the newer pass owns the execution
        }
        $after = $this->countTimerCompleted($id);

        if ($after > $before || $timedOut) {
            WorkflowTaskJournal::schedule($this->eventStore, $this->resumeDispatcher, $id);
            $this->resumeDispatcher->dispatchResume($id);

            return;
        }

        // No timer fired yet: re-schedule the check so the workflow eventually resumes
        // once the timer delay actually elapses (needed when the transport delivered
        // the message earlier than expected, e.g. in-memory transport + DelayStamp).
        $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
            $this->eventStore,
            $id,
            $this->runtime->nowSeconds(),
        );

        if (null !== $ms) {
            $this->timerDispatcher->dispatchTimerFire(ExecutionId::fromString($message->executionId), max(0, $ms));
        }
    }

    /**
     * A child that has no outcome when its deadline falls fails its parent's await with the text
     * Temporal gives it, and ends in its own journal so that it no longer counts as running (#979).
     * A child that reported first is not in the pending list: its outcome stands.
     *
     * @return bool whether a child was failed
     */
    private function failChildrenPastTheirDeadline(ExecutionId $parent, EventStoreInterface $journal): bool
    {
        $failed = false;
        foreach (PendingChildDeadlines::dueAt($this->eventStore, $parent, $this->runtime->nowSeconds()) as $childId) {
            // DUR052 §3: announced before the outcome is appended, as a child's own report is.
            $this->resumeDispatcher->dispatchResumeAwaiting($parent, AwaitedFact::child($childId));
            $message = \sprintf('Child workflow %s timed out.', $childId);
            $child = ExecutionId::fromString($childId);
            // The child's end first: a crash between the two appends leaves a deadline still pending,
            // which fires again, and never a failed parent with a child that runs on. Ended whether
            // it started or not: a start still queued finds the end and runs nothing.
            $this->eventStore->append(WorkflowExecutionFailed::terminatedByParent($child, $parent, $message));
            $journal->append(new ChildWorkflowFailed($parent, $child, $message));
            // As ParentChildWorkflowCoordinator does for a closed parent: a child that waits on a
            // signal or a timer replays now, finds its end, and its own children follow their policy.
            WorkflowTaskJournal::schedule($this->eventStore, $this->resumeDispatcher, $child);
            $this->resumeDispatcher->dispatchResume($child);
            $failed = true;
        }

        return $failed;
    }

    private function countTimerCompleted(ExecutionId $executionId): int
    {
        $n = 0;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof TimerCompleted) {
                ++$n;
            }
        }

        return $n;
    }
}
