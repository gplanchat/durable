<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Mapping;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ParentClosePolicy;

/**
 * Maps **journal records** to domain {@see Event} instances and back.
 *
 * A record is the JSON-shaped payload used everywhere the journal is stored or transported:
 * - rows in {@see \Gplanchat\Durable\Store\InMemoryEventStore} ou tout autre {@see \Gplanchat\Durable\Store\EventStoreInterface};
 * - items embedded in Temporal **gRPC** workflow history (e.g. {@code WORKFLOW_EXECUTION_STARTED} input
 *   decoded by {@see \Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload}).
 *
 * This is **not** a generic serializer: it is the boundary between wire/storage data and the durable event model.
 */
final class EventDataMapper
{
    /**
     * @return array{execution_id: string, event_type: string, payload: array<string, mixed>}
     */
    public static function fromDomainEvent(Event $event): array
    {
        return [
            'execution_id' => $event->executionId(),
            'event_type' => $event::class,
            'payload' => $event->payload(),
        ];
    }

    /**
     * @param array<string, mixed> $record Must contain execution_id, event_type, payload (same shape as gRPC-decoded journal items and DB rows).
     */
    public static function toDomainEvent(array $record): Event
    {
        $eventType = $record['event_type'];
        if (!\is_string($eventType)) {
            throw new \InvalidArgumentException('toDomainEvent: missing event_type');
        }
        $executionId = $record['execution_id'];
        if (!\is_string($executionId)) {
            throw new \InvalidArgumentException('toDomainEvent: missing execution_id');
        }
        $rawPayload = $record['payload'] ?? null;
        $payload = \is_string($rawPayload) ? json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR) : $rawPayload;
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('toDomainEvent: payload must be array or JSON object');
        }
        /* @var array<string, mixed> $payload */

        return match ($eventType) {
            ExecutionStarted::class => new ExecutionStarted($executionId, $payload),
            ExecutionCompleted::class => new ExecutionCompleted($executionId, $payload['result'] ?? null),
            ActivityScheduled::class => new ActivityScheduled(
                $executionId,
                (string) $payload['activityId'],
                (string) $payload['activityName'],
                \is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
                \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ),
            ActivityCancelled::class => new ActivityCancelled(
                $executionId,
                (string) $payload['activityId'],
                (string) $payload['reason'],
            ),
            ActivityCompleted::class => new ActivityCompleted($executionId, (string) $payload['activityId'], $payload['result'] ?? null),
            ActivityTaskStarted::class => new ActivityTaskStarted(
                $executionId,
                (string) $payload['activityId'],
                (string) $payload['activityName'],
                (int) ($payload['attempt'] ?? 1),
            ),
            ActivityTaskCompleted::class => new ActivityTaskCompleted(
                $executionId,
                (string) $payload['activityId'],
                $payload['result'] ?? null,
            ),
            ActivityFailed::class => new ActivityFailed(
                $executionId,
                (string) $payload['activityId'],
                (string) $payload['failureClass'],
                (string) $payload['failureMessage'],
                (int) ($payload['failureCode'] ?? 0),
                \is_array($payload['failureContext'] ?? null) ? $payload['failureContext'] : [],
                (string) ($payload['failureTrace'] ?? ''),
                \is_array($payload['failurePrevious'] ?? null) ? $payload['failurePrevious'] : [],
                (string) ($payload['activityName'] ?? ''),
                (int) ($payload['failureAttempt'] ?? 0),
            ),
            ActivityCatastrophicFailure::class => ActivityCatastrophicFailure::fromStoredPayload($executionId, $payload),
            WorkflowExecutionFailed::class => WorkflowExecutionFailed::fromStoredPayload($executionId, $payload),
            TimerScheduled::class => new TimerScheduled(
                $executionId,
                (string) $payload['timerId'],
                (float) $payload['scheduledAt'],
                isset($payload['summary']) ? (string) $payload['summary'] : '',
            ),
            TimerCompleted::class => new TimerCompleted($executionId, (string) $payload['timerId']),
            SideEffectRecorded::class => new SideEffectRecorded($executionId, (string) $payload['sideEffectId'], $payload['result'] ?? null),
            ChildWorkflowScheduled::class => self::toDomainEventChildWorkflowScheduled($executionId, $payload),
            ChildWorkflowCompleted::class => new ChildWorkflowCompleted($executionId, (string) $payload['childExecutionId'], $payload['result'] ?? null),
            ChildWorkflowFailed::class => self::toDomainEventChildWorkflowFailed($executionId, $payload),
            WorkflowContinuedAsNew::class => new WorkflowContinuedAsNew(
                $executionId,
                (string) $payload['nextWorkflowType'],
                \is_array($payload['nextPayload'] ?? null) ? $payload['nextPayload'] : [],
                \is_array($payload['continuationMetadata'] ?? null) ? $payload['continuationMetadata'] : [],
            ),
            WorkflowSignalReceived::class => new WorkflowSignalReceived(
                $executionId,
                (string) $payload['signalName'],
                \is_array($payload['signalPayload'] ?? null) ? $payload['signalPayload'] : [],
            ),
            WorkflowUpdateHandled::class => new WorkflowUpdateHandled(
                $executionId,
                (string) $payload['updateName'],
                \is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [],
                $payload['result'] ?? null,
            ),
            WorkflowCancellationRequested::class => self::toDomainEventWorkflowCancellationRequested($executionId, $payload),
            default => throw new \InvalidArgumentException(\sprintf('Unknown event type: %s', $eventType)),
        };
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function toDomainEventChildWorkflowScheduled(string $executionId, array $p): ChildWorkflowScheduled
    {
        $policyValue = $p['parentClosePolicy'] ?? ParentClosePolicy::Terminate->value;
        $policy = ParentClosePolicy::from(\is_string($policyValue) ? $policyValue : ParentClosePolicy::Terminate->value);

        $requestedWorkflowId = $p['requestedWorkflowId'] ?? null;
        if ('' === $requestedWorkflowId) {
            $requestedWorkflowId = null;
        }

        $scheduling = $p['schedulingMetadata'] ?? [];
        if (!\is_array($scheduling)) {
            $scheduling = [];
        }

        return new ChildWorkflowScheduled(
            $executionId,
            (string) $p['childExecutionId'],
            (string) $p['childWorkflowType'],
            \is_array($p['input'] ?? null) ? $p['input'] : [],
            $policy,
            \is_string($requestedWorkflowId) ? $requestedWorkflowId : null,
            $scheduling,
        );
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function toDomainEventChildWorkflowFailed(string $executionId, array $p): ChildWorkflowFailed
    {
        $ctx = $p['workflowFailureContext'] ?? [];
        if (!\is_array($ctx)) {
            $ctx = [];
        }

        return new ChildWorkflowFailed(
            $executionId,
            (string) $p['childExecutionId'],
            (string) $p['failureMessage'],
            (int) ($p['failureCode'] ?? 0),
            isset($p['workflowFailureKind']) ? (string) $p['workflowFailureKind'] : null,
            isset($p['workflowFailureClass']) ? (string) $p['workflowFailureClass'] : null,
            $ctx,
        );
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function toDomainEventWorkflowCancellationRequested(string $executionId, array $p): WorkflowCancellationRequested
    {
        $source = $p['sourceParentExecutionId'] ?? null;
        if ('' === $source) {
            $source = null;
        }

        return new WorkflowCancellationRequested(
            $executionId,
            (string) $p['reason'],
            \is_string($source) ? $source : null,
        );
    }
}
