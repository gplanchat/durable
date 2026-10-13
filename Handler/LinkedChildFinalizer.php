<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowTaskJournal;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector;

/**
 * Tells the parent of a linked child that the child ended: what every ending of a run runs, whether
 * the run ended on a worker or from outside through the client.
 */
final readonly class LinkedChildFinalizer
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private WorkflowResumeDispatcher $resumeDispatcher,
        private ChildWorkflowParentLinkStoreInterface $childWorkflowParentLinkStore,
    ) {}

    public function finalize(ExecutionId $childId, mixed $result, ?\Throwable $failure): void
    {
        $parent = $this->childWorkflowParentLinkStore->getParentExecutionId($childId);
        if (null === $parent) {
            return;
        }
        // The parent awaits the id it scheduled: the first run of the chain, whichever run ends it (#859).
        $scheduledId = $this->firstRunOfTheChain($childId);

        // DUR052 §3: announced first, appended once, resumed, and unlinked last. A child resume
        // redelivered after a crash still finds the link, and resumes the parent without a second
        // outcome.
        // The fact is wire: it carries the child id as a string.
        $child = AwaitedFact::child($scheduledId->toString());
        if (!$child->isJournalledIn($this->eventStore, $parent)) {
            $this->resumeDispatcher->dispatchResumeAwaiting($parent, $child);
            $this->eventStore->append(null !== $failure
                ? AsyncChildWorkflowFailureProjector::toParentJournalEvent($this->eventStore, $parent, $scheduledId, $failure, $childId)
                : new ChildWorkflowCompleted($parent, $scheduledId, $result));
        }

        WorkflowTaskJournal::schedule($this->eventStore, $this->resumeDispatcher, $parent);
        $this->resumeDispatcher->dispatchResume($parent);
        $this->childWorkflowParentLinkStore->unlink($childId);
    }

    /**
     * Walks back the `continuedFromExecutionId` each continuation writes into its run's start.
     *
     * ponytail: reads the head of every run's stream, at the end of a linked child only; a column on
     * the parent link would spare the reads if chains of linked children grow long.
     */
    private function firstRunOfTheChain(ExecutionId $executionId): ExecutionId
    {
        while (true) {
            $predecessor = null;
            foreach ($this->eventStore->readStream($executionId) as $event) {
                // The first start is the run's own: the walk reads no further, and stops at the root.
                if ($event instanceof ExecutionStarted) {
                    $from = $event->payload()['continuedFromExecutionId'] ?? null;
                    $predecessor = \is_string($from) ? ExecutionId::fromString($from) : null;
                    break;
                }
            }
            if (null === $predecessor) {
                return $executionId;
            }
            $executionId = $predecessor;
        }
    }
}
