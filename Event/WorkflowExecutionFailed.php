<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Le handler de workflow n'a pas géré une erreur (ex. échec d'activité non attrapé) :
 * défaillance d'algorithme / d'intégration côté workflow.
 */
final readonly class WorkflowExecutionFailed implements Event
{
    public const KIND_UNHANDLED_ACTIVITY = 'unhandled_activity_failure';
    public const KIND_UNHANDLED_DECLARED_ACTIVITY = 'unhandled_declared_activity_failure';
    public const KIND_UNHANDLED_ACTIVITY_SUPERSEDED = 'unhandled_activity_superseded';
    public const KIND_UNHANDLED_CATASTROPHIC_ACTIVITY = 'unhandled_catastrophic_activity_failure';
    public const KIND_WORKFLOW_HANDLER = 'workflow_handler_failure';
    public const KIND_TERMINATED_BY_PARENT = 'terminated_by_parent';

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $executionId,
        private string $kind,
        private string $failureClass,
        private string $failureMessage,
        private int $failureCode,
        private array $context,
    ) {
    }

    /**
     * @param array<string, mixed> $p
     */
    public static function fromStoredPayload(string $executionId, array $p): self
    {
        return new self(
            $executionId,
            (string) $p['kind'],
            (string) $p['failureClass'],
            (string) $p['failureMessage'],
            (int) ($p['failureCode'] ?? 0),
            \is_array($p['context'] ?? null) ? $p['context'] : [],
        );
    }

    public static function unhandledActivityFailure(string $executionId, string $activityId, string $activityName, \Throwable $cause): self
    {
        return new self(
            $executionId,
            self::KIND_UNHANDLED_ACTIVITY,
            $cause::class,
            $cause->getMessage(),
            (int) $cause->getCode(),
            [
                'activityId' => $activityId,
                'activityName' => $activityName,
            ],
        );
    }

    public static function unhandledDeclaredActivityFailure(string $executionId, \Throwable $cause): self
    {
        return new self(
            $executionId,
            self::KIND_UNHANDLED_DECLARED_ACTIVITY,
            $cause::class,
            $cause->getMessage(),
            (int) $cause->getCode(),
            [],
        );
    }

    public static function unhandledActivitySuperseded(string $executionId, \Throwable $cause): self
    {
        return new self(
            $executionId,
            self::KIND_UNHANDLED_ACTIVITY_SUPERSEDED,
            $cause::class,
            $cause->getMessage(),
            (int) $cause->getCode(),
            [],
        );
    }

    public static function unhandledCatastrophicActivity(string $executionId, \Throwable $cause): self
    {
        return new self(
            $executionId,
            self::KIND_UNHANDLED_CATASTROPHIC_ACTIVITY,
            $cause::class,
            $cause->getMessage(),
            (int) $cause->getCode(),
            [],
        );
    }

    public static function workflowHandlerFailure(string $executionId, \Throwable $e): self
    {
        return new self(
            $executionId,
            self::KIND_WORKFLOW_HANDLER,
            $e::class,
            $e->getMessage(),
            (int) $e->getCode(),
            [],
        );
    }

    public static function terminatedByParent(string $childExecutionId, string $parentExecutionId, string $message = 'Child workflow terminated: parent closed'): self
    {
        return new self(
            $childExecutionId,
            self::KIND_TERMINATED_BY_PARENT,
            self::class,
            $message,
            0,
            ['parentExecutionId' => $parentExecutionId],
        );
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function failureClass(): string
    {
        return $this->failureClass;
    }

    public function failureMessage(): string
    {
        return $this->failureMessage;
    }

    public function failureCode(): int
    {
        return $this->failureCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function payload(): array
    {
        return [
            'kind' => $this->kind,
            'failureClass' => $this->failureClass,
            'failureMessage' => $this->failureMessage,
            'failureCode' => $this->failureCode,
            'context' => $this->context,
        ];
    }
}
