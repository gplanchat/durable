<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;

/**
 * Adapte une tâche Nexus entrante vers la méthode que le gestionnaire a écrite.
 *
 * Sans lui, l'écart entre les deux bouts est double, et les deux moitiés sont des `TypeError` :
 * {@see NexusOperationRegistry::dispatch()} appelle son gestionnaire avec **la charge entière en
 * argument #1** et attend un {@see NexusOperationResponse}, quand le gestionnaire a écrit la
 * signature de son contrat et rend le type que celui-ci déclare.
 *
 * L'association est celle des activités, au mot près — la charge est clée par nom de paramètre à
 * l'écriture ({@see \Gplanchat\Durable\Nexus\NexusStub::argumentsToPayload()}) et relue par nom
 * ici —, d'où la réutilisation de {@see PayloadToContractMethodInvoker} plutôt qu'une seconde copie
 * de la même boucle.
 *
 * Ce qui reste en propre est l'emballage : un gestionnaire immédiat rend une valeur métier, et
 * c'est la plomberie qui en fait une réponse. L'écrire dans le gestionnaire obligerait chacun à
 * connaître un type de la plomberie pour dire « voilà ».
 */
final readonly class NexusHandlerInvoker
{
    private PayloadToContractMethodInvoker $invoker;

    /**
     * @param class-string $contractClass
     */
    public function __construct(
        object $handler,
        private string $contractClass,
        private string $contractMethodName,
    ) {
        $this->invoker = new PayloadToContractMethodInvoker($handler, $contractClass, $contractMethodName);
    }

    public function __invoke(mixed $payload): NexusOperationResponse
    {
        if (!\is_array($payload)) {
            // Frontière de confiance : la charge vient du réseau, et un appelant qui n'est pas un
            // stub Durable peut envoyer n'importe quoi. La refuser en la nommant vaut mieux que de
            // laisser la réflexion échouer sur un message qui parle de paramètres.
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus payload for %s::%s() must be a JSON object keyed by parameter name, got %s.',
                $this->contractClass,
                $this->contractMethodName,
                get_debug_type($payload),
            ));
        }

        return NexusOperationResponse::completed(($this->invoker)($payload));
    }
}
