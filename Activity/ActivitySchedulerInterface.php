<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Awaitable\Awaitable;

/**
 * Ce dont un {@see ActivityStub} a besoin, et rien de plus : de quoi planifier une activité.
 *
 * Le stub recevait l'environnement entier alors qu'il n'en utilise qu'un verbe. Le lui donner
 * lui laissait de quoi dormir, courir, se relancer — et surtout obligeait à garder publique la
 * primitive `activity(string $name, array $payload)`, celle que la bibliothèque n'enseigne plus :
 * une faute de frappe y produit une activité qui n'est jamais planifiée, au lieu d'une erreur de
 * type.
 *
 * Ce port n'est pas porté par {@see \Gplanchat\Durable\WorkflowEnvironment} : l'implémenter y
 * reviendrait à rendre le verbe public sous un autre nom. Il est porté par un adaptateur que
 * l'environnement construit et ne rend jamais.
 */
interface ActivitySchedulerInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function scheduleActivity(string $activityName, array $payload, ?ActivityOptions $options): Awaitable;
}
