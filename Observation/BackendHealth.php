<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Le backend répond-il, maintenant.
 *
 * Distinct de « un backend est configuré » : un catalogue enregistré dont la base est tombée
 * afficherait sinon un tableau de bord vide et serein, ce qui est la pire des deux erreurs
 * possibles — l'exploitant conclut qu'il n'y a rien à voir.
 *
 * `backend` nomme ce qui a été sondé — « SQL database », « Temporal » — parce qu'un exploitant qui
 * lit « injoignable » a besoin de savoir quoi aller rallumer. C'est l'inverse exact du cas où
 * *aucun* backend n'est configuré : là, nommer un serveur qui n'a jamais été de la partie
 * l'enverrait sur une fausse piste.
 */
final readonly class BackendHealth
{
    public function __construct(
        public string $backend,
        public bool $reachable,
        public string $message,
        public \DateTimeImmutable $checkedAt,
    ) {}
}
