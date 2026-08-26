<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ChildWorkflowOptions;

/**
 * Ce dont un {@see ChildWorkflowStub} a besoin : de quoi démarrer un enfant, et rien de plus.
 *
 * Pendant de {@see \Gplanchat\Durable\Activity\ActivitySchedulerInterface} pour les activités, et
 * pour la même raison : le stub recevait l'environnement entier, ce qui obligeait à garder
 * publique la forme qui nomme le type d'enfant par une chaîne.
 *
 * Le port **démarre** et rend un awaitable ; il n'attend pas. C'est la règle de DUR033 — `await()`
 * est la seule méthode qui attend — dont le stub s'était écarté.
 */
interface ChildWorkflowSchedulerInterface
{
    /**
     * @param array<string, mixed> $input
     *
     * @return Awaitable<mixed>
     */
    public function startChildWorkflow(string $childWorkflowType, array $input, ?ChildWorkflowOptions $options): Awaitable;
}
