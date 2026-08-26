<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Duration;

/**
 * L'échéance passée à {@see \Gplanchat\Durable\WorkflowEnvironment::await()} ou à
 * {@see \Gplanchat\Durable\WorkflowEnvironment::waitSignal()} s'est écoulée avant que le travail
 * attendu ne se règle.
 *
 * Une défaillance, pas une valeur : `null` est une réponse qu'un travail borné a le droit de
 * rendre, et le point de cette échéance est justement de l'en distinguer (ADR DUR032).
 *
 * À ne pas confondre avec {@see \Gplanchat\Durable\Activity\ActivityTimeouts}, qui borne une
 * tentative d'activité côté serveur. Celle-ci borne *cette* attente, dans *cette* exécution.
 */
final class DeadlineExceededException extends \RuntimeException
{
    public function __construct(
        private readonly Duration $deadline,
        private readonly string $awaited,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Deadline of %s elapsed while awaiting %s', $deadline, $awaited),
            0,
            $previous,
        );
    }

    public function deadline(): Duration
    {
        return $this->deadline;
    }

    /** Ce qui était attendu, tel qu'il peut être nommé depuis le journal (activité, minuteur, signal). */
    public function awaited(): string
    {
        return $this->awaited;
    }
}
