<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;

/**
 * Environnement de test in-memory complet pour les workflows.
 *
 * Regroupe l'EventStore, le transport d'activités et le registre d'activités
 * pour offrir une façade simple à l'utilisateur du composant.
 *
 * Usage :
 * ```php
 * $env = WorkflowTestEnvironment::inMemory([
 *     'greet' => fn(array $p) => 'Hello, ' . $p['name'] . '!',
 * ]);
 *
 * $result = $env->run(function (WorkflowEnvironment $wf) {
 *     return $wf->await($wf->activity('greet', ['name' => 'World']));
 * });
 *
 * self::assertSame('Hello, World!', $result);
 * ```
 */
final class WorkflowTestEnvironment
{
    private readonly InMemoryEventStore $eventStore;
    private readonly InMemoryActivityTransport $activityTransport;
    private readonly RegistryActivityExecutor $activityExecutor;
    private readonly InMemoryWorkflowRunner $runner;

    private function __construct(int $maxActivityRetries = 0)
    {
        $this->eventStore = new InMemoryEventStore();
        $this->activityTransport = new InMemoryActivityTransport();
        $this->activityExecutor = new RegistryActivityExecutor();
        $this->runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $maxActivityRetries,
        );
    }

    /**
     * Crée un environnement de test in-memory avec des handlers d'activités optionnels.
     *
     * @param array<string, callable(array<string, mixed>): mixed> $activityHandlers Map nomActivité → callable
     * @param int $maxActivityRetries Nombre de tentatives max par activité (0 = aucun retry)
     */
    public static function inMemory(array $activityHandlers = [], int $maxActivityRetries = 0): self
    {
        $env = new self($maxActivityRetries);
        foreach ($activityHandlers as $activityName => $handler) {
            $env->activityExecutor->register($activityName, $handler);
        }

        return $env;
    }

    /**
     * Enregistre (ou remplace) un handler d'activité.
     *
     * Compatible avec {@see ActivitySpy} :
     * ```php
     * $spy = ActivitySpy::returns('ok');
     * $env->register('my.activity', $spy);
     * ```
     *
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function register(string $activityName, callable $handler): void
    {
        $this->activityExecutor->register($activityName, $handler);
    }

    /**
     * Exécute un workflow jusqu'à complétion ou échec.
     *
     * @param callable(\Gplanchat\Durable\WorkflowEnvironment): mixed $handler
     * @param string|null $executionId ID de l'exécution (généré aléatoirement si null)
     *
     * @return mixed Résultat retourné par le handler workflow
     */
    public function run(callable $handler, ?string $executionId = null): mixed
    {
        $id = $executionId ?? $this->generateExecutionId();

        return $this->runner->run($id, $handler);
    }

    /**
     * Accès direct à l'EventStore pour inspecter les événements enregistrés.
     *
     * Utile pour les assertions personnalisées :
     * ```php
     * foreach ($env->getEventStore()->readStream($executionId) as $event) {
     *     if ($event instanceof ExecutionCompleted) { ... }
     * }
     * ```
     */
    public function getEventStore(): InMemoryEventStore
    {
        return $this->eventStore;
    }

    /**
     * Accès direct au transport d'activités (inspection de la file).
     */
    public function getActivityTransport(): InMemoryActivityTransport
    {
        return $this->activityTransport;
    }

    /**
     * Accès direct au runner sous-jacent.
     *
     * Utile quand une méthode de test nécessite de passer explicitement
     * le runner plutôt que d'utiliser la façade.
     */
    public function getRunner(): InMemoryWorkflowRunner
    {
        return $this->runner;
    }

    private function generateExecutionId(): string
    {
        return 'test-exec-'.bin2hex(random_bytes(8));
    }
}
