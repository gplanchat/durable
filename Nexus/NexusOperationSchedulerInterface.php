<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;

/**
 * Ce dont un {@see NexusStub} a besoin, et rien de plus : de quoi planifier une opération.
 *
 * Même montage que {@see \Gplanchat\Durable\Activity\ActivitySchedulerInterface}, et pour la même
 * raison. `nexusOperation(endpoint, service, operation, payload)` nommait trois choses par des
 * chaînes libres : le même nom s'écrivait à deux endroits, chez l'appelant et chez le gestionnaire,
 * sans rien qui les relie — et une faute de frappe y produit une opération qui attend un
 * gestionnaire dont le nom ne correspondra jamais, au lieu d'une erreur de type. C'est exactement
 * ce que DUR039 avait retiré de la surface pour les activités, et que le côté Nexus avait gardé.
 *
 * Ce port n'est pas porté par {@see \Gplanchat\Durable\WorkflowEnvironment} : l'implémenter y
 * reviendrait à rendre le verbe public sous un autre nom.
 */
interface NexusOperationSchedulerInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function scheduleNexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        ?NexusOperationTimeouts $timeouts,
    ): Awaitable;
}
