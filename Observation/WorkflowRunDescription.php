<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Ce qu'un backend sait dire d'une exécution, dans le vocabulaire du composant et non dans le sien.
 *
 * Les faits qu'un backend n'a pas sont **absents**, jamais une chaîne vide : une colonne « file de
 * tâches » vide apprend à l'exploitant que l'exécution n'a pas de file, alors que c'est le backend
 * qui n'a pas la notion. D'où des propriétés nullables plutôt que des valeurs de remplissage.
 *
 * `groupId` porte le regroupement quand le backend en a un — Temporal conserve le workflow id à
 * travers les continuations et donne à chaque exécution son propre run id. Le backend DBAL n'a pas
 * cette notion et le laisse absent.
 */
final readonly class WorkflowRunDescription
{
    public function __construct(
        public string $runId,
        public string $workflowName,
        public WorkflowRunStatus $status,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?string $groupId = null,
    ) {}
}
