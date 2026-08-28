<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;

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
    private readonly WorkflowRegistry $workflowRegistry;
    private readonly InMemoryWorkflowRunner $runner;

    private function __construct(int $maxActivityRetries = 0, float $budgetSeconds = InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS)
    {
        $this->eventStore = new InMemoryEventStore();
        $this->activityTransport = new InMemoryActivityTransport();
        $this->activityExecutor = new RegistryActivityExecutor();
        $this->workflowRegistry = new WorkflowRegistry();
        $this->runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $maxActivityRetries,
            $this->workflowRegistry,
            $budgetSeconds,
        );
    }

    /**
     * Crée un environnement de test in-memory avec des handlers d'activités optionnels.
     *
     * @param array<string, callable(array<string, mixed>): mixed> $activityHandlers Map nomActivité → callable
     * @param int   $maxActivityRetries Plafond de retentatives quand l'activité n'en fixe pas
     *                                  (0 = pas de plafond ; les ActivityOptions restent maîtres)
     * @param float $budgetSeconds      Durée max d'une exécution : les tentatives d'activité étant
     *                                  illimitées par défaut, un harnais en ligne a besoin d'une borne
     */
    public static function inMemory(
        array $activityHandlers = [],
        int $maxActivityRetries = 0,
        float $budgetSeconds = InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS,
    ): self {
        $env = new self($maxActivityRetries, $budgetSeconds);
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
    /**
     * Enregistre un type de workflow, pour qu'il soit démarrable comme **enfant**
     * ({@see \Gplanchat\Durable\WorkflowEnvironment::executeChildWorkflow()}).
     *
     * @param callable(array<string, mixed>): callable $factory Reçoit l'input, retourne le handler
     */
    public function registerWorkflow(string $workflowType, callable $factory): void
    {
        $this->workflowRegistry->registerFactory($workflowType, $factory);
    }

    /**
     * Enregistre un workflow défini par attributs / classe.
     *
     * @param class-string $workflowClass
     */
    public function registerWorkflowClass(string $workflowClass): void
    {
        $this->workflowRegistry->registerClass($workflowClass);
    }

    /**
     * Exécute un workflow **classe**, dans sa forme de production.
     *
     * L'environnement atteint le constructeur, l'input atteint la méthode marquée
     * {@see \Gplanchat\Durable\Attribute\AsWorkflowMethod} — exactement comme sur un backend.
     *
     * C'est la forme à préférer. {@see run()} prend une closure qui reçoit l'environnement : une
     * signature qu'aucun workflow n'a depuis que l'environnement est passé au constructeur. Elle
     * reste, pour les workflows anonymes de trois lignes, mais c'est la forme du harnais et non
     * celle d'un workflow.
     *
     * ```php
     * $result = $env->runWorkflowClass(CheckoutWorkflow::class, ['orderId' => 'ORD-1']);
     * ```
     *
     * @param class-string             $workflowClass
     * @param array<string, mixed>     $input       Arguments métier, appariés par nom
     * @param string|null              $executionId ID d'exécution (généré si null)
     */
    public function runWorkflowClass(string $workflowClass, array $input = [], ?string $executionId = null): mixed
    {
        // Idempotent : un test peut enregistrer la classe lui-même pour la démarrer aussi comme
        // enfant, et n'a pas à savoir laquelle des deux voies l'a fait en premier.
        if (!$this->workflowRegistry->has($workflowClass)) {
            $this->registerWorkflowClass($workflowClass);
        }

        return $this->run($this->workflowRegistry->getHandler($workflowClass, $input), $executionId);
    }

    public function getWorkflowRegistry(): WorkflowRegistry
    {
        return $this->workflowRegistry;
    }

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
        return 'test-exec-' . bin2hex(random_bytes(8));
    }
}
