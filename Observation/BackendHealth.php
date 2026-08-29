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
 *
 * `ephemeral` est le **troisième** état, et il ne se déduit d'aucun des deux autres : le backend
 * répond, et sa réponse est vide parce que son journal ne survit pas au processus qui l'écrit. Sous
 * PHP-FPM, la requête qui rend le tableau de bord n'a jamais exécuté le moindre workflow. Rangé
 * sous « joignable », ce cas apprend à l'exploitant qu'aucun workflow n'a tourné, ce qui est faux ;
 * rangé sous « injoignable », il l'envoie rallumer un serveur qui n'existe pas.
 *
 * Le défaut est `false` : les trois catalogues qui écrivent hors du processus — SQL, Illuminate,
 * Temporal — n'ont pas à déclarer ce qui est vrai d'eux par construction.
 */
final readonly class BackendHealth
{
    public function __construct(
        public string $backend,
        public bool $reachable,
        public string $message,
        public \DateTimeImmutable $checkedAt,
        public bool $ephemeral = false,
    ) {}
}
