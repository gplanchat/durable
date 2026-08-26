<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;

/**
 * Une opération Nexus n'a pas abouti, et l'exception dit **pourquoi** et **où**.
 *
 * Le pourquoi est {@see NexusOperationFailureKind} : quatre natures qui appellent quatre gestes
 * différents. Le où est le triplet endpoint / service / opération, exigé par le spec pour qu'un
 * échec non rattrapé nomme le site d'appel — sans lui, un workflow qui parle à trois endpoints
 * tombe sans dire lequel.
 *
 * Le comportement de reprise ne voyage que sur {@see NexusOperationFailureKind::HandlerError} :
 * c'est le serveur qui l'énonce, et seulement quand le handler n'a pas tourné.
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
     * Ce que le serveur dit de la reprise, et seulement pour une erreur de handler.
     */
    public function retryBehaviour(): ?string
    {
        return NexusOperationFailureKind::HandlerError === $this->kind ? $this->retryBehaviour : null;
    }
}
