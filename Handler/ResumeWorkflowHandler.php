<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector;
use Gplanchat\Durable\Workflow\PendingUpdate;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

/*
 * Moved down from the bundle package into the core. This was not a host adapter: over 138 lines,
 * fifteen imports came from the core and six from Symfony, and those six served only two things —
 * a v7 identifier, which `ExecutionId` already makes, and the timer wake-up, which is now a port.
 * Six hosts of the selector do not go through the bundle; leaving it there would have meant as
 * many copies of the resume semantics, divergent at the first fix.
 */
final readonly class ResumeWorkflowHandler
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly EventStoreInterface $eventStore,
        private readonly ChildWorkflowParentLinkStoreInterface $childWorkflowParentLinkStore,
        private readonly WorkflowTimerDispatcher $timerDispatcher,
        private readonly WorkflowDefinitionLoader $workflowDefinitionLoader,
        private readonly ?WorkflowRunPickupProjectionInterface $pickups = null,
    ) {}

    public function __invoke(ResumeWorkflowMessage $message): void
    {
        // The message is wire: its id is a string. The ports take the value object (#638).
        $executionId = $message->executionId;
        $id = ExecutionId::fromString($executionId);

        $metadata = $this->metadataStore->get($id);
        if (null === $metadata) {
            return;
        }
        if (($metadata['completed'] ?? false) === true) {
            return;
        }

        // Sent before the fact it announces (DUR050, DUR052): until that fact is journalled, this
        // resume concludes nothing, and the transport's retry is the wait.
        if (null !== $message->awaited && !$message->awaited->isJournalledIn($this->eventStore, $id)) {
            throw new ResumeArrivedBeforeItsOutcome($executionId, $message->awaited);
        }

        // A worker has the run now (#447). Recorded here rather than on ExecutionStarted: resume()
        // never appends it, and a run that waits on a signal appends nothing at all.
        $this->pickups?->recordPickup($id);

        $lookupKey = $metadata['workflowType'];
        $payload = $metadata['payload'];

        $handler = $this->workflowRegistry->getHandler($lookupKey, $payload);
        $workflowTypeForJournal = $this->workflowDefinitionLoader->aliasForTemporalInterop($lookupKey);

        try {
            $pendingUpdates = array_map(
                static fn(array $update): PendingUpdate => new PendingUpdate($update['name'], $update['arguments']),
                $message->pendingUpdates,
            );

            $result = $this->engine->resume($executionId, $handler, $workflowTypeForJournal, $pendingUpdates);
        } catch (WorkflowSuspendedException $e) {
            // The catalog that records pickups usually records waits too (#324): one projection, two facts.
            // Recorded even without words, so that it clears the previous wait instead of leaving it stale.
            if ($this->pickups instanceof WorkflowRunWaitProjectionInterface) {
                $this->pickups->recordWait($id, $e->waitingOn());
            }
            if ($e->shouldDispatchResume()) {
                if (!$e->waitingOnTimer()) {
                    $this->resumeDispatcher->dispatchResume($id);
                } else {
                    $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
                        $this->eventStore,
                        $executionId,
                        $this->engine->getRuntime()->nowSeconds(),
                    );
                    if (null === $ms) {
                        $ms = 0;
                    }
                    $this->timerDispatcher->dispatchTimerFire($id, max(0, $ms));
                }
            }

            return;
        } catch (ContinueAsNewRequested $e) {
            $newId = null !== $e->nextExecutionId ? ExecutionId::fromString($e->nextExecutionId) : ExecutionId::generate();
            // The parent link follows the chain (#859), as Temporal does. The next run is linked before
            // it can start and finish. The old run keeps its link until it is marked completed: a
            // redelivery before that replays it, possibly under another next id, and must still find
            // the parent to link that one. After it, only a stale link on a completed run remains.
            $parent = $this->childWorkflowParentLinkStore->getParentExecutionId($id);
            if (null !== $parent) {
                $this->childWorkflowParentLinkStore->link($newId, $parent);
            }
            // Superseded, not deleted (#322): the row is what the old run was started with.
            $this->metadataStore->markCompleted($id);
            if (null !== $parent) {
                $this->childWorkflowParentLinkStore->unlink($id);
            }
            $nextAlias = $this->workflowDefinitionLoader->aliasForTemporalInterop($e->workflowType);
            $this->metadataStore->save($newId, $nextAlias, $e->payload);
            // resume() never writes a start: this one is the only place the new run names its predecessor.
            $this->eventStore->append(new ExecutionStarted($newId, [
                'workflowType' => $nextAlias,
                'continuedFromExecutionId' => $executionId,
            ]));
            $this->resumeDispatcher->dispatchNewWorkflowRun($newId, $nextAlias, $e->payload);

            return;
        } catch (WorkflowCancelledException $e) {
            // Normal termination: do not dispatch the resume again, otherwise the cancellation
            // would be redelivered indefinitely. The parent is notified as for a failure.
            $this->finalizeAsyncChildOnParentIfLinked($id, null, $e);
            // Marked, not deleted — as on the success path below. A completed row is inactive, so
            // no resume picks it up; deleting it would also destroy the start payload, which on a
            // backend that writes no `ExecutionStarted` lives nowhere else.
            $this->metadataStore->markCompleted($id);

            return;
        } catch (SupersededPassException) {
            // A newer pass has claimed the execution (DUR053): it owns the run, this one only stops.
            return;
        } catch (\Throwable $e) {
            $this->finalizeAsyncChildOnParentIfLinked($id, null, $e);
            // An execution that failed is the one an operator most wants to look at: keeping what
            // it was started with costs a row and answers "given what?".
            $this->metadataStore->markCompleted($id);

            throw $e;
        }

        $this->finalizeAsyncChildOnParentIfLinked($id, $result, null);
        $this->metadataStore->markCompleted($id);
    }

    private function finalizeAsyncChildOnParentIfLinked(ExecutionId $childId, mixed $result, ?\Throwable $failure): void
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
