<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;

/**
 * A Nexus operation did not succeed, and the exception says **why** and **where**.
 *
 * The why is {@see NexusOperationFailureKind}: four natures that call for four different moves.
 * The where is the endpoint / service / operation triplet, required by the spec so that an
 * uncaught failure names the call site — without it, a workflow that talks to three endpoints
 * goes down without saying which one.
 *
 * The retry behaviour only travels on {@see NexusOperationFailureKind::HandlerError}: it is the
 * server that states it, and only when the handler did not run.
 */
final class DurableNexusOperationFailedException extends \Exception
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $service,
        private readonly string $operation,
        private readonly NexusOperationFailureKind $kind,
        private readonly FailureEnvelope $envelope,
        private readonly ?string $retryBehaviour = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Nexus operation "%s" on service "%s" at endpoint "%s" did not complete (%s): %s',
                $operation,
                $service,
                $endpoint,
                $kind->value,
                $envelope->message,
            ),
            $envelope->code,
            $previous,
        );
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function kind(): NexusOperationFailureKind
    {
        return $this->kind;
    }

    public function envelope(): FailureEnvelope
    {
        return $this->envelope;
    }

    /**
     * What the server says about the retry, and only for a handler error.
     */
    public function retryBehaviour(): ?string
    {
        return NexusOperationFailureKind::HandlerError === $this->kind ? $this->retryBehaviour : null;
    }
}
