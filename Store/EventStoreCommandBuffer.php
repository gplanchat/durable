<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Implements WorkflowCommandBufferInterface by appending domain events to EventStoreInterface
 * and enqueuing activity messages via ActivityTransportInterface.
 *
 * Used by the in-memory backend. The Temporal backend uses TemporalWorkflowCommandBuffer instead.
 */
final class EventStoreCommandBuffer implements WorkflowCommandBufferInterface
{
    /** @var callable(): float */
    private $clock;

    /**
     * @param (callable(): float)|null $clock horloge du backend ; injectable pour les harnais qui
     *                                        avancent un temps virtuel
     */
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly string $executionId,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void
    {
        // C'est ici, dans l'adaptateur, que les options prennent leur forme de fil — et que la
        // mise en file est horodatée, avec l'horloge de ce backend.
        $queuedAt = ($this->clock)();
        $metadata = ($options?->toMetadata() ?? []) + [
            'queued_at' => $queuedAt,
            'first_queued_at' => $queuedAt,
        ];

        $this->eventStore->append(new ActivityScheduled(
            $this->executionId,
            $activityId,
            $activityName,
            $payload,
            $metadata,
        ));
        $this->activityTransport->enqueue(new ActivityMessage(
            $this->executionId,
            $activityId,
            $activityName,
            $payload,
            $options,
            firstQueuedAt: $queuedAt,
        ));
    }

    public function startTimer(string $timerId, Duration $delay, string $summary): void
    {
        // Ce backend compare des échéances à son horloge : c'est ici que le délai en devient une.
        $this->eventStore->append(new TimerScheduled(
            $this->executionId,
            $timerId,
            ($this->clock)() + $delay->toSeconds(),
            $summary,
        ));
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        $this->eventStore->append(new SideEffectRecorded(
            $this->executionId,
            $sideEffectId,
            $result,
        ));
    }

    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
        $this->eventStore->append(new WorkflowUpdateHandled(
            $this->executionId,
            $updateName,
            $arguments,
            $result,
            $failure,
        ));
    }

    public function scheduleChildWorkflow(
        string $childExecutionId,
        string $childWorkflowType,
        array $input,
        ChildWorkflowOptions $options,
    ): void {
        // La forme de fil se fabrique ici : le journal enregistre les métadonnées plates que
        // l'ancien code lui donnait déjà, y compris les deux clés que le cœur ajoutait à la main.
        $this->eventStore->append(new ChildWorkflowScheduled(
            $this->executionId,
            $childExecutionId,
            $childWorkflowType,
            $input,
            $options->parentClosePolicy,
            $options->workflowId,
            [
                'parentClosePolicy' => $options->parentClosePolicy,
                'workflowId' => $options->workflowId,
            ] + $options->toSchedulingMetadata(),
        ));
    }

    public function completeWorkflow(mixed $result): void
    {
        $this->eventStore->append(new ExecutionCompleted(
            $this->executionId,
            $result,
        ));
    }

    public function completeChildWorkflow(string $childExecutionId, mixed $result): void
    {
        $this->eventStore->append(new ChildWorkflowCompleted(
            $this->executionId,
            $childExecutionId,
            $result,
        ));
    }

    public function failChildWorkflow(string $childExecutionId, \Throwable $reason): void
    {
        $this->eventStore->append(new ChildWorkflowFailed(
            $this->executionId,
            $childExecutionId,
            $reason->getMessage(),
            (int) $reason->getCode(),
        ));
    }

    public function recordVersion(string $changeId, int $version): void
    {
        $this->eventStore->append(new VersionMarked($this->executionId, $changeId, $version));
    }

    public function failWorkflow(\Throwable $reason): void
    {
        $this->eventStore->append(WorkflowExecutionFailed::workflowHandlerFailure(
            $this->executionId,
            $reason,
        ));
    }

    public function cancelTimer(string $timerId, string $reason): void
    {
        // Le replay repasse par cancelLosers() à chaque reprise : sans ce garde le journal
        // accumulerait un TimerCancelled par replay.
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerCancelled && $event->timerId() === $timerId) {
                return;
            }
        }

        $this->eventStore->append(new TimerCancelled($this->executionId, $timerId, $reason));
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        $this->activityTransport->removePendingFor($this->executionId, $activityId);
        $this->eventStore->append(new ActivityCancelled(
            $this->executionId,
            $activityId,
            $reason,
        ));
    }

    public function scheduleNexusOperation(
        string $operationId,
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        NexusOperationTimeouts $timeouts,
        NexusOperationHeaders $headers,
    ): void {
        throw NexusUnsupportedByBackendException::forBackend('journal');
    }

    public function cancelNexusOperation(string $operationId, string $reason): void
    {
        throw NexusUnsupportedByBackendException::forBackend('journal');
    }
}
