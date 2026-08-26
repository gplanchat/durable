<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger pour reprendre un workflow suspendu.
 *
 * Pour démarrer un nouveau workflow, utiliser {@see WorkflowResumeDispatcher::dispatchNewWorkflowRun}
 * qui persiste les métadonnées et dispatch ce message.
 *
 * @see \Gplanchat\Durable\Port\WorkflowResumeDispatcher
 */
final readonly class ResumeWorkflowMessage
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $pendingUpdates updates à
     *        remettre à l'exécution pour cette passe. Ils n'ont pas encore de position dans le
     *        journal : c'est la passe qui les applique qui les y inscrit, avant ce que le workflow
     *        en fait. Des tableaux et non des objets, parce que ce message est sérialisé.
     */
    public function __construct(
        public string $executionId,
        public array $pendingUpdates = [],
    ) {}
}
