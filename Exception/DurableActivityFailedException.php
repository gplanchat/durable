<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

final class DurableActivityFailedException extends \Exception
{
    public function __construct(
        private readonly string $activityId,
        private readonly string $activityName,
        private readonly int $attempt,
        private readonly FailureEnvelope $envelope,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            self::formatMessage($activityId, $activityName, $attempt, $envelope),
            $envelope->code,
            $previous,
        );
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function activityName(): string
    {
        return $this->activityName;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function envelope(): FailureEnvelope
    {
        return $this->envelope;
    }

    public static function fromActivityFailed(ActivityFailed $event): self
    {
        $envelope = new FailureEnvelope(
            $event->failureClass(),
            $event->failureMessage(),
            $event->failureCode(),
            $event->failureContext(),
            '' !== $event->failureTrace() ? $event->failureTrace() : null,
            $event->failurePrevious(),
        );

        return new self(
            $event->activityId(),
            $event->activityName(),
            $event->failureAttempt(),
            $envelope,
            self::syntheticPreviousFromChain($event->failurePrevious()),
        );
    }

    /**
     * @param list<array{class: string, message: string, code: int}> $chain
     */
    private static function syntheticPreviousFromChain(array $chain): ?\Throwable
    {
        if ([] === $chain) {
            return null;
        }

        $previous = null;
        foreach (array_reverse($chain) as $link) {
            $cls = (string) $link['class'];
            $msg = (string) $link['message'];
            $code = (int) $link['code'];
            $previous = new ActivityFailureCauseException($cls, $msg, $code, $previous);
        }

        return $previous;
    }

    /**
     * Exception à propager vers {@see \Gplanchat\Durable\WorkflowEnvironment::await()} : restauration déclarée ou enveloppe durable.
     */
    public static function toThrowable(ActivityFailed $event): \Throwable
    {
        $ctx = $event->failureContext();
        if (true === ($ctx['_durable_declared'] ?? false)) {
            $class = $ctx['_durable_declared_class'] ?? '';
            if (\is_string($class) && is_a($class, DeclaredActivityFailureInterface::class, true)) {
                try {
                    return $class::restoreFromActivityFailureContext(
                        \is_array($ctx['_durable_declared_payload'] ?? null) ? $ctx['_durable_declared_payload'] : [],
                    );
                } catch (\Throwable) {
                    // repli sur l'exception générique durable
                }
            }
        }

        return self::fromActivityFailed($event);
    }

    private static function formatMessage(
        string $activityId,
        string $activityName,
        int $attempt,
        FailureEnvelope $envelope,
    ): string {
        $namePart = '' !== $activityName ? $activityName.' / ' : '';

        return \sprintf(
            '[%s%s] attempt=%d — %s: %s',
            $namePart,
            $activityId,
            $attempt,
            $envelope->class,
            $envelope->message,
        );
    }
}
