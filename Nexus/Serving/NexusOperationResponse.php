<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

/**
 * Ce qu'un gestionnaire répond à une opération : maintenant, ou plus tard.
 *
 * **Le contrat de la forme différée est « démarre ce workflow », et non « rends un jeton ».** La
 * sonde 3.1 l'a mesuré contre un serveur réel, dans les deux sens : ce qui fait revenir la
 * complétion à l'appelant, c'est le `callback` de la tâche attaché au workflow qui remplit
 * l'opération, via `completion_callbacks` — un champ qui ne se pose qu'au démarrage. Sans lui,
 * l'historique de l'appelant s'arrête à `NEXUS_OPERATION_STARTED` et plus rien n'arrive, quel que
 * soit le jeton rendu.
 *
 * Le jeton n'est donc pas le mécanisme, seulement l'identifiant. Un gestionnaire qui devrait le
 * fabriquer lui-même choisirait la seule pièce qui ne corrèle rien, et la plomberie ne pourrait
 * plus attacher le callback à temps — le démarrage est déjà passé.
 *
 * Une fois le workflow démarré avec ce callback, le gestionnaire n'est plus sollicité : c'est le
 * serveur qui corrèle la fin du workflow à l'opération.
 */
final readonly class NexusOperationResponse
{
    private function __construct(
        public bool $isImmediate,
        public mixed $result,
        public ?string $workflowType,
        public array $workflowInput,
        public ?string $workflowId,
    ) {}

    /**
     * Le gestionnaire a la réponse tout de suite.
     *
     * Rappel de la sonde 1.7 : cette réponse doit partir en moins de ~9 s, l'horloge du
     * `request-timeout`. Au-delà, la tâche est redélivrée et le travail recommence.
     */
    public static function completed(mixed $result): self
    {
        return new self(true, $result, null, [], null);
    }

    /**
     * L'opération est remplie par un workflow, dont le résultat deviendra celui de l'opération.
     *
     * @param array<mixed> $input
     */
    public static function fulfilledByWorkflow(string $workflowType, array $input = [], ?string $workflowId = null): self
    {
        if ('' === trim($workflowType)) {
            throw new \InvalidArgumentException('A Nexus operation fulfilled by a workflow needs a workflow type.');
        }

        return new self(false, null, $workflowType, $input, $workflowId);
    }
}
