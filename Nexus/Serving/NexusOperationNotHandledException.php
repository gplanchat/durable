<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;

/**
 * Personne ne sert cette opération.
 *
 * L'erreur est **terminale**, et c'est le point : `NOT_IMPLEMENTED` est du côté non réessayable de
 * la table de 1b.3. La dire réessayable ferait redemander la même opération toutes les ~9 secondes
 * pendant tout le budget de l'opération (sonde 1.7), pour une réponse qui ne changera pas — le
 * gestionnaire n'apparaîtra pas entre deux tentatives.
 */
final class NexusOperationNotHandledException extends \RuntimeException
{
    public function __construct(
        public readonly NexusService $service,
        public readonly NexusOperationName $operation,
    ) {
        parent::__construct(\sprintf(
            'No Nexus handler is registered for operation "%s" of service "%s".',
            $operation->name(),
            $service->name(),
        ));
    }

    public function type(): NexusHandlerErrorType
    {
        return NexusHandlerErrorType::NotImplemented;
    }
}
