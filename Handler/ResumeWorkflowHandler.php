<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowTaskCompleted;
use Gplanchat\Durable\Event\WorkflowTaskStarted;
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
use Gplanchat\Durable\Store\WorkflowTaskJournal;
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
            // The run continued as new, and the send of its next run may have been lost after the
            // run was marked completed: a send held until the handler returns (Messenger), or a
            // chain broken before #881. The journal names that run; it is sent again if not done.
            // Any later resume of the old run, a timer it never awaited for instance, takes this path
            // too: one more send of a next run still active, which replays it.
            foreach ($this->eventStore->readStream($id) as $event) {
                if ($event instanceof WorkflowContinuedAsNew && null !== $event->newExecutionId()) {
                    $this->continueAsNew($id, $event->newExecutionId(), $event->nextWorkflowType(), $event->nextPayload());
                    break;
                }
            }

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
        // The queue ends here: what lies between the dispatch and this append is the wait for a worker.
        $this->eventStore->append(new WorkflowTaskStarted($id));

        $lookupKey = $metadata['workflowType'];
        $payload = $metadata['payload'];

        $handler = $this->workflowRegistry->getHandler($lookupKey, $payload);
        $workflowTypeForJournal = $this->workflowDefinitionLoader->aliasForTemporalInterop($lookupKey);

        try {
            $pendingUpdates = array_map(
                static fn(array $update): PendingUpdate => new PendingUpdate($update['name'], $update['arguments']),
                $message->pendingUpdates,
            );

            $result = $this->engine->resume($id, $handler, $workflowTypeForJournal, $pendingUpdates);
        } catch (WorkflowSuspendedException $e) {
            // Closed before anything is dispatched, so the next task is scheduled after this one ends.
            $this->eventStore->append(new WorkflowTaskCompleted($id));
            // The catalog that records pickups usually records waits too (#324): one projection, two facts.
            // Recorded even without words, so that it clears the previous wait instead of leaving it stale.
            if ($this->pickups instanceof WorkflowRunWaitProjectionInterface) {
                $this->pickups->recordWait($id, $e->waitingOn());
            }
            if ($e->shouldDispatchResume()) {
                if (!$e->waitingOnTimer()) {
                    WorkflowTaskJournal::schedule($this->eventStore, $this->resumeDispatcher, $id);
                    $this->resumeDispatcher->dispatchResume($id);
                } else {
                    $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
                        $this->eventStore,
                        $id,
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
            $this->eventStore->append(new WorkflowTaskCompleted($id));
            $newId = $e->nextExecutionId ?? ExecutionId::generate();
            $this->continueAsNew($id, $newId, $e->workflowType, $e->payload);

            return;
        } catch (WorkflowCancelledException $e) {
            $this->eventStore->append(new WorkflowTaskCompleted($id));
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
            $this->eventStore->append(new WorkflowTaskCompleted($id));
            $this->finalizeAsyncChildOnParentIfLinked($id, null, $e);
            // An execution that failed is the one an operator most wants to look at: keeping what
            // it was started with costs a row and answers "given what?".
            $this->metadataStore->markCompleted($id);

            throw $e;
        }

        $this->eventStore->append(new WorkflowTaskCompleted($id));
        $this->finalizeAsyncChildOnParentIfLinked($id, $result, null);
        $this->metadataStore->markCompleted($id);
    }

    /**
     * The old run is marked completed last (#881): until then, a redelivery replays it under the
     * same next id (#878) and does again whatever a crash left undone. Each step is skipped once
     * done. A next run that already finished is not touched at all: save() sets `completed` back
     * to false, which would reopen it. A run that finishes after this read stays finished too: the
     * dispatchers write its row only when it has none (#918).
     *
     * @param array<string, mixed> $payload
     */
    private function continueAsNew(ExecutionId $id, ExecutionId $newId, string $workflowType, array $payload): void
    {
        $next = $this->metadataStore->get($newId);
        $parent = $this->childWorkflowParentLinkStore->getParentExecutionId($id);
        if (($next['completed'] ?? false) !== true) {
            // The parent link follows the chain (#859), as Temporal does: linked before the next
            // run can start and finish.
            if (null !== $parent) {
                $this->childWorkflowParentLinkStore->link($newId, $parent);
            }
            $nextAlias = $this->workflowDefinitionLoader->aliasForTemporalInterop($workflowType);
            if (null === $next) {
                $this->metadataStore->save($newId, $nextAlias, $payload);
            }
            // resume() never writes a start: this one is the only place the new run names its predecessor.
            if (0 === $this->eventStore->countEventsInStream($newId)) {
                $this->eventStore->append(new ExecutionStarted($newId, [
                    'workflowType' => $nextAlias,
                    'continuedFromExecutionId' => $id->toString(),
                ]));
            }
            // A second dispatch is a second resume of the same run, which replays.
            WorkflowTaskJournal::schedule($this->eventStore, $this->resumeDispatcher, $newId);
            $this->resumeDispatcher->dispatchNewWorkflowRun($newId, $nextAlias, $payload);
        }
        // Superseded, not deleted (#322): the row is what the old run was started with.
        $this->metadataStore->markCompleted($id);
        if (null !== $parent) {
            $this->childWorkflowParentLinkStore->unlink($id);
        }
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
