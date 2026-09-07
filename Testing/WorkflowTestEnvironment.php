<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * A complete in-memory test environment for workflows.
 *
 * It brings together the EventStore, the activity transport and the activity registry
 * to offer a simple facade to the user of the component.
 *
 * Usage:
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
     * Creates an in-memory test environment with optional activity handlers.
     *
     * @param array<string, callable(array<string, mixed>): mixed> $activityHandlers Map activityName → callable
     * @param int   $maxActivityRetries Retry ceiling when the activity does not set one
     *                                  (0 = no ceiling; the ActivityOptions stay in charge)
     * @param float $budgetSeconds      Max duration of an execution: activity attempts being
     *                                  unlimited by default, an inline harness needs a bound
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
     * Registers (or replaces) an activity handler.
     *
     * Compatible with {@see ActivitySpy}:
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
     * Runs a workflow to completion or failure.
     *
     * @param callable(\Gplanchat\Durable\WorkflowEnvironment): mixed $handler
     * @param string|null $executionId ID of the execution (generated at random if null)
     *
     * @return mixed Result returned by the workflow handler
     */
    public function run(callable $handler, ?string $executionId = null): mixed
    {
        $id = $executionId ?? $this->generateExecutionId();

        return $this->runner->run($id, $handler);
    }

    /**
     * Direct access to the EventStore, to inspect the events it recorded.
     *
     * Useful for assertions of your own:
     * ```php
     * foreach ($env->getEventStore()->readStream($executionId) as $event) {
     *     if ($event instanceof ExecutionCompleted) { ... }
     * }
     * ```
     */
    /**
     * Registers a workflow type, so that it can be started as a **child**
     * ({@see \Gplanchat\Durable\WorkflowEnvironment::executeChildWorkflow()}).
     *
     * @param callable(array<string, mixed>): callable $factory Takes the input, returns the handler
     */
    public function registerWorkflow(string $workflowType, callable $factory): void
    {
        $this->workflowRegistry->registerFactory($workflowType, $factory);
    }

    /**
     * Registers a workflow defined by attributes / class.
     *
     * @param class-string $workflowClass
     */
    public function registerWorkflowClass(string $workflowClass): void
    {
        $this->workflowRegistry->registerClass($workflowClass);
    }

    /**
     * Runs a **class** workflow, in its production form.
     *
     * The environment reaches the constructor, the input reaches the method marked
     * {@see \Gplanchat\Durable\Attribute\AsWorkflowMethod} — exactly as on a backend.
     *
     * This is the form to prefer. {@see run()} takes a closure that receives the environment: a
     * signature no workflow has had since the environment is passed to the constructor. It stays,
     * for three-line anonymous workflows, but it is the form of the harness and not that of a
     * workflow.
     *
     * ```php
     * $result = $env->runWorkflowClass(CheckoutWorkflow::class, ['orderId' => 'ORD-1']);
     * ```
     *
     * @param class-string             $workflowClass
     * @param array<string, mixed>     $input       Business arguments, matched by name
     * @param string|null              $executionId Execution ID (generated if null)
     */
    public function runWorkflowClass(string $workflowClass, array $input = [], ?string $executionId = null): mixed
    {
        // Idempotent: a test may register the class itself to start it as a child too, and does
        // not have to know which of the two paths did it first.
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
     * Direct access to the activity transport (queue inspection).
     */
    public function getActivityTransport(): InMemoryActivityTransport
    {
        return $this->activityTransport;
    }

    /**
     * Direct access to the underlying runner.
     *
     * Useful when a test method requires the runner to be passed
     * explicitly rather than using the facade.
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
