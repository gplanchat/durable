<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Uuid\NativeUuidV7Generator;
use Gplanchat\Durable\Uuid\UuidGeneratorInterface;

final class ExecutionContext
{
    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingActivities = [];

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingNexusOperations = [];

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingTimers = [];

    private int $activitySlotIndex = 0;

    private int $nexusOperationSlotIndex = 0;

    private int $timerSlotIndex = 0;

    private int $sideEffectSlotIndex = 0;

    private int $childWorkflowSlotIndex = 0;

    /**
     * Rang du prochain message non appliqué. Reconstruit à zéro à chaque passe, avancé par la
     * même règle sur le même journal : c'est ce qui rend le verdict d'une condition reproductible.
     */
    private int $messageCursor = 0;

    public function __construct(
        private readonly string $executionId,
        private readonly WorkflowHistorySourceInterface $historySource,
        private readonly WorkflowCommandBufferInterface $commandBuffer,
        private readonly ?ChildWorkflowRunnerInterface $childWorkflowRunner = null,
        private readonly ?UuidGeneratorInterface $uuidGenerator = null,
        /**
         * Les updates que la passe reçoit hors journal. Ils viennent après tout ce qui est
         * enregistré : n'ayant pas encore de position, ils prennent celle de leur arrivée.
         *
         * @var list<\Gplanchat\Durable\Workflow\PendingUpdate>
         */
        private readonly array $pendingUpdates = [],
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function activity(string $name, array $payload = [], ?ActivityOptions $options = null): Awaitable
    {
        $slotIndex = $this->activitySlotIndex++;
        $replay = $this->historySource->findActivitySlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }
            $replayActivityId = $this->historySource->findScheduledActivityId($slotIndex) ?? '';

            return new ActivityAwaitable($deferred->awaitable(), $replayActivityId);
        }

        $scheduled = $this->historySource->findScheduledActivityId($slotIndex);
        if (null !== $scheduled) {
            $activityId = $scheduled;
        } else {
            $optId = $options?->activityId;
            $activityId = (null !== $optId && '' !== $optId) ? $optId : $this->uuid();
        }
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingActivities[$activityId] = $deferred;

        if (null === $scheduled) {
            // Les options partent telles quelles ; l'horodatage de mise en file appartient au
            // backend, qui seul possède une horloge.
            $this->commandBuffer->scheduleActivity($activityId, $name, $payload, $options);
        }

        return new ActivityAwaitable($deferred->awaitable(), $activityId);
    }

    /**
     * Planifie une opération Nexus et rend l'attente de son résultat.
     *
     * Même discipline de slot que {@see activity()} : le rang de l'appel identifie l'opération
     * d'une passe de replay à l'autre. La différence de conséquence mérite d'être dite — une
     * activité replanifiée par erreur retombe sur un worker à soi, une opération Nexus part chez
     * un tiers, où le doublon est le sien.
     *
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function nexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload = [],
        ?NexusOperationTimeouts $timeouts = null,
        ?NexusOperationHeaders $headers = null,
    ): Awaitable {
        $slotIndex = $this->nexusOperationSlotIndex++;
        $scheduled = $this->historySource->findScheduledNexusOperation($slotIndex);
        $operationId = $scheduled ?? $this->uuid();

        $replay = $this->historySource->findNexusOperationSlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }

            return new NexusOperationAwaitable($deferred->awaitable(), $operationId);
        }

        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingNexusOperations[$operationId] = $deferred;

        if (null === $scheduled) {
            $this->commandBuffer->scheduleNexusOperation(
                $operationId,
                $endpoint,
                $service,
                $operation,
                $payload,
                $timeouts ?? NexusOperationTimeouts::none(),
                $headers ?? NexusOperationHeaders::none(),
            );
        }

        return new NexusOperationAwaitable($deferred->awaitable(), $operationId);
    }

    /**
     * Executes a potentially non-deterministic closure once; on replay, reuses the result recorded in history.
     *
     * @param \Closure(): mixed $closure
     *
     * @return Awaitable<mixed>
     */
    public function sideEffect(\Closure $closure): Awaitable
    {
        $slotIndex = $this->sideEffectSlotIndex++;
        $replayResult = $this->historySource->findSideEffectForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replayResult) {
            $deferred->resolve($replayResult);

            return $deferred->awaitable();
        }

        $result = $closure();
        $this->commandBuffer->recordSideEffect($this->uuid(), $result);
        $deferred->resolve($result);

        return $deferred->awaitable();
    }

    /**
     * @return Awaitable<mixed>
     */
    public function timer(Duration $delay, string $timerSummary = ''): Awaitable
    {
        return $this->delay($delay, $timerSummary);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ContinueAsNewRequested always
     */
    public function continueAsNew(string $workflowType, array $payload = [], ?ContinueAsNewOptions $options = null): never
    {
        throw new ContinueAsNewRequested($workflowType, $payload, $options);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Awaitable<mixed>
     */
    public function executeChildWorkflow(string $childWorkflowType, array $input = [], ?ChildWorkflowOptions $options = null): Awaitable
    {
        if (null === $this->childWorkflowRunner) {
            throw new \LogicException('ChildWorkflowRunner is not configured on ExecutionContext.');
        }

        $options ??= ChildWorkflowOptions::defaults();

        $slotIndex = $this->childWorkflowSlotIndex++;
        $replay = $this->historySource->findChildWorkflowForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replay) {
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }

            return $deferred->awaitable();
        }

        $scheduledId = $this->historySource->findScheduledChildExecutionId($slotIndex);
        $childExecutionId = $scheduledId ?? ($options->workflowId ?? $this->uuid());

        if (null === $scheduledId && null !== $options->workflowId) {
            $this->assertChildWorkflowIdAllowed($options, $childExecutionId);
        }

        if (null === $scheduledId) {
            $this->commandBuffer->scheduleChildWorkflow($childExecutionId, $childWorkflowType, $input, $options);
        }

        if (null !== $scheduledId && $this->childWorkflowRunner->defersChildStart()) {
            return $deferred->awaitable();
        }

        try {
            $result = $this->childWorkflowRunner->runChild($childExecutionId, $childWorkflowType, $input, $this->executionId);
            // L'issue de l'ENFANT, pas celle du run courant : completeWorkflow() ici clôturait le
            // journal du parent avec le résultat de l'enfant, et n'écrivait jamais le
            // ChildWorkflowCompleted que findChildWorkflowForSlot() cherche au replay — l'enfant
            // était donc réexécuté à chaque reprise du parent.
            $this->commandBuffer->completeChildWorkflow($childExecutionId, $result);
            $deferred->resolve($result);
        } catch (ChildWorkflowStartDeferred) {
            return $deferred->awaitable();
        } catch (\Throwable $e) {
            $this->commandBuffer->failChildWorkflow($childExecutionId, $e);
            $deferred->reject(new DurableChildWorkflowFailedException(
                $childExecutionId,
                $e->getMessage(),
                (int) $e->getCode(),
                $e,
            ));
        }

        return $deferred->awaitable();
    }

    /**
     * Applique le prochain message enregistré, s'il en reste un avant `$beforePosition`.
     *
     * Un par un, jamais par lot : un message enregistré après le tir d'une échéance ne doit pas
     * régler la condition qu'elle bornait, et une condition satisfaite par le premier de deux
     * messages doit reprendre en n'ayant vu que celui-là. Les deux sortent de la même règle —
     * le verdict est une position dans le journal (ADR DUR035).
     *
     * `pending` porte l'update hors journal quand c'en est un, et null quand le message est relu
     * du journal : c'est ce qui distingue « produire l'issue » de « refaire l'état ».
     *
     * @return array{kind: 'signal'|'update', name: string, payload: array<string, mixed>, pending: \Gplanchat\Durable\Workflow\PendingUpdate|null}|null
     */
    public function nextMessage(?int $beforePosition = null): ?array
    {
        $message = $this->historySource->messageAt($this->messageCursor);
        if (null !== $message) {
            if (null !== $beforePosition && $message['position'] > $beforePosition) {
                return null;
            }

            ++$this->messageCursor;

            return [
                'kind' => $message['kind'],
                'name' => $message['name'],
                'payload' => $message['payload'],
                'pending' => null,
            ];
        }

        // Le journal est épuisé : restent les updates arrivés hors journal pour cette passe.
        $recorded = $this->countRecordedMessages();
        $pending = $this->pendingUpdates[$this->messageCursor - $recorded] ?? null;
        if (null === $pending) {
            return null;
        }

        ++$this->messageCursor;

        return ['kind' => 'update', 'name' => $pending->name, 'payload' => $pending->arguments, 'pending' => $pending];
    }

    private function countRecordedMessages(): int
    {
        $count = 0;
        while (null !== $this->historySource->messageAt($count)) {
            ++$count;
        }

        return $count;
    }

    /**
     * Consigne l'issue d'un update qui vient d'être traité, à la position où il l'a été.
     *
     * @param array<string, mixed> $arguments
     */
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
        $this->commandBuffer->recordUpdateHandled($updateName, $arguments, $result, $failure);
    }

    /**
     * Position à laquelle le tir de ce minuteur est enregistré, ou null s'il n'a pas tiré.
     */
    public function timerCompletionPosition(string $timerId): ?int
    {
        return $this->historySource->timerCompletionPosition($timerId);
    }

    /**
     * Cancels a pending activity (best effort).
     */
    public function cancelScheduledActivity(string $activityId, string $reason): bool
    {
        if (!isset($this->pendingActivities[$activityId])) {
            return false;
        }

        $this->commandBuffer->cancelActivity($activityId, $reason);
        $this->rejectActivity($activityId, ActivityCancellationReason::WORKFLOW_CANCELLED === $reason
            ? new WorkflowCancelledFailure($this->executionId, $reason)
            : new ActivitySupersededException($activityId, $reason));

        return true;
    }

    /**
     * Retire une opération Nexus encore en vol (best effort).
     *
     * Comme pour une activité, la demande part vers l'endpoint sans garantie qu'il l'honore : ce
     * qui est garanti est que sa réponse ne réveillera plus cette exécution. L'annulation du
     * workflow rejette l'attente pour qu'il puisse compenser ; un perdant de course reste
     * simplement non réglé.
     */
    public function cancelScheduledNexusOperation(string $operationId, string $reason): bool
    {
        if (!isset($this->pendingNexusOperations[$operationId])) {
            return false;
        }

        $deferred = $this->pendingNexusOperations[$operationId];
        unset($this->pendingNexusOperations[$operationId]);
        $this->commandBuffer->cancelNexusOperation($operationId, $reason);

        if (ActivityCancellationReason::WORKFLOW_CANCELLED === $reason) {
            $deferred->reject(new WorkflowCancelledFailure($this->executionId, $reason));
        }

        return true;
    }

    /**
     * Annule un minuteur encore en attente (best effort).
     *
     * Le minuteur ne sera jamais résolu : il est retiré des pending pour que
     * {@see resolveTimer()} devienne un no-op, et le journal reçoit un
     * {@see \Gplanchat\Durable\Event\TimerCancelled}.
     */
    public function cancelScheduledTimer(string $timerId, string $reason): bool
    {
        if (!isset($this->pendingTimers[$timerId])) {
            return false;
        }

        $deferred = $this->pendingTimers[$timerId];
        unset($this->pendingTimers[$timerId]);
        $this->commandBuffer->cancelTimer($timerId, $reason);

        // Un perdant de course reste simplement non résolu ; une annulation de workflow doit
        // au contraire relever, pour que le workflow puisse compenser.
        if (ActivityCancellationReason::WORKFLOW_CANCELLED === $reason) {
            $deferred->reject(new WorkflowCancelledFailure($this->executionId, $reason));
        }

        return true;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function delay(Duration $delay, string $timerSummary = ''): Awaitable
    {
        $slotIndex = $this->timerSlotIndex++;
        $replay = $this->historySource->findTimerSlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== ($replay['failed'] ?? null)) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve(null);
            }

            return new TimerAwaitable($deferred->awaitable(), $replay['id']);
        }

        $scheduled = $this->historySource->findScheduledTimerId($slotIndex);
        $timerId = $scheduled ?? $this->uuid();
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingTimers[$timerId] = $deferred;

        if (null === $scheduled) {
            // Le délai part tel quel : transformer une durée en échéance demande une horloge, et
            // le cœur n'en a pas — c'est une décision de backend.
            $this->commandBuffer->startTimer($timerId, $delay, $timerSummary);
        }

        return new TimerAwaitable($deferred->awaitable(), $timerId);
    }

    /**
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    public function pendingTimers(): array
    {
        return $this->pendingTimers;
    }

    public function resolveTimer(string $timerId): void
    {
        $deferred = $this->pendingTimers[$timerId] ?? null;
        if (null !== $deferred) {
            $deferred->resolve(null);
            unset($this->pendingTimers[$timerId]);
        }
    }

    /**
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    /**
     * Les opérations Nexus encore en vol, par identifiant.
     *
     * Miroir de {@see pendingActivities()} : c'est par là que l'issue d'une opération, lue dans
     * l'historique, retrouve l'attente qu'elle doit régler.
     *
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    public function pendingNexusOperations(): array
    {
        return $this->pendingNexusOperations;
    }

    public function pendingActivities(): array
    {
        return $this->pendingActivities;
    }

    public function resolveActivity(string $activityId, mixed $result): void
    {
        $deferred = $this->pendingActivities[$activityId] ?? null;
        if (null !== $deferred) {
            $deferred->resolve($result);
            unset($this->pendingActivities[$activityId]);
        }
    }

    public function rejectActivity(string $activityId, \Throwable $reason): void
    {
        $deferred = $this->pendingActivities[$activityId] ?? null;
        if (null !== $deferred) {
            $deferred->reject($reason);
            unset($this->pendingActivities[$activityId]);
        }
    }

    private function assertChildWorkflowIdAllowed(ChildWorkflowOptions $options, string $childExecutionId): void
    {
        if (WorkflowIdReusePolicy::AllowDuplicate === $options->workflowIdReusePolicy) {
            return;
        }

        if (!$this->historySource->hasChildExecutionId($childExecutionId)) {
            return;
        }

        if (WorkflowIdReusePolicy::RejectDuplicate === $options->workflowIdReusePolicy) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s is already used in the event store.', $childExecutionId));
        }

        if ($this->historySource->hasChildExecutionCompletedSuccessfully($childExecutionId)) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s already completed successfully; reuse is not allowed with AllowDuplicateFailedOnly.', $childExecutionId));
        }
    }

    private function uuid(): string
    {
        return ($this->uuidGenerator ?? new NativeUuidV7Generator())->generate();
    }
}
