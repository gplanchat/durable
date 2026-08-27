<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une exécution a rencontré un point de changement, et la réponse qu'elle a reçue est désormais
 * la sienne — pour toujours.
 *
 * C'est cet enregistrement qui distingue le versioning de la devinette : au replay, la réponse
 * vient d'ici et non du code déployé, donc une exécution en vol garde son comportement quoi qu'on
 * déploie ensuite.
 */
final readonly class VersionMarked implements Event
{
    public function __construct(
        private string $executionId,
        private string $changeId,
        private int $version,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function changeId(): string
    {
        return $this->changeId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function payload(): array
    {
        return [
            'changeId' => $this->changeId,
            'version' => $this->version,
        ];
    }
}
