<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector;
use Gplanchat\Durable\Workflow\PendingUpdate;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

/*
 * Descendu du paquet du bundle vers le cœur. Ce n'était pas un adaptateur d'hôte : sur 138 lignes,
 * quinze imports venaient du cœur et six de Symfony, ces six-là ne servant qu'à deux choses — un
 * identifiant v7, que `ExecutionId` fabrique déjà, et le réveil des minuteries, qui est désormais
 * un port. Six hôtes du sélecteur ne passent pas par le bundle ; les y laisser aurait voulu dire
 * autant de copies de la sémantique de reprise, divergentes à la première correction.
 */
final class ResumeWorkflowHandler
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
    ) {}

    public function __invoke(ResumeWorkflowMessage $message): void
    {
        $executionId = $message->executionId;

        $metadata = $this->metadataStore->get($executionId);
        if (null === $metadata) {
            return;
        }
        if (($metadata['completed'] ?? false) === true) {
            return;
        }

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
            if ($e->shouldDispatchResume()) {
                if (!$e->waitingOnTimer()) {
                    $this->resumeDispatcher->dispatchResume($executionId);
                } else {
                    $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
                        $this->eventStore,
                        $executionId,
                        $this->engine->getRuntime()->nowSeconds(),
                    );
                    if (null === $ms) {
                        $ms = 0;
                    }
                    $this->timerDispatcher->dispatchTimerFire($executionId, max(0, $ms));
                }
            }

            return;
        } catch (ContinueAsNewRequested $e) {
            $this->metadataStore->delete($executionId);
            $newExecutionId = ExecutionId::generate()->toString();
            $nextAlias = $this->workflowDefinitionLoader->aliasForTemporalInterop($e->workflowType);
            $this->metadataStore->save($newExecutionId, $nextAlias, $e->payload);
            $this->resumeDispatcher->dispatchNewWorkflowRun($newExecutionId, $nextAlias, $e->payload);

            return;
        } catch (WorkflowCancelledException $e) {
            // Terminaison normale : ne pas relancer la reprise, sinon l'annulation serait
            // redélivrée indéfiniment. Le parent est notifié comme pour un échec.
            $this->finalizeAsyncChildOnParentIfLinked($executionId, null, $e);
            $this->metadataStore->delete($executionId);

            return;
        } catch (\Throwable $e) {
            $this->finalizeAsyncChildOnParentIfLinked($executionId, null, $e);
            $this->metadataStore->delete($executionId);

            throw $e;
        }

        $this->finalizeAsyncChildOnParentIfLinked($executionId, $result, null);
        $this->metadataStore->markCompleted($executionId);
    }

    private function finalizeAsyncChildOnParentIfLinked(string $childExecutionId, mixed $result, ?\Throwable $failure): void
    {
        $parentId = $this->childWorkflowParentLinkStore->getParentExecutionId($childExecutionId);
        if (null === $parentId) {
            return;
        }

        if (null !== $failure) {
            $this->eventStore->append(AsyncChildWorkflowFailureProjector::toParentJournalEvent(
                $this->eventStore,
                $parentId,
                $childExecutionId,
                $failure,
            ));
        } else {
            $this->eventStore->append(new ChildWorkflowCompleted($parentId, $childExecutionId, $result));
        }

        $this->childWorkflowParentLinkStore->unlink($childExecutionId);
        $this->resumeDispatcher->dispatchResume($parentId);
    }
}
