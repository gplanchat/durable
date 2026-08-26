<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\CancellingAnyAwaitable;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Façade par exécution : encapsule ExecutionContext et ExecutionRuntime.
 * Seule API workflow côté applicatif — pas de fonctions libres ni scope TLS.
 */
final class WorkflowEnvironment
{
    private ?ActivityContractResolver $activityResolver = null;

    private ?WorkflowDefinitionLoader $workflowLoader = null;

    /** @var array<string, callable> query type → handler */
    private array $queryHandlers = [];

    public function __construct(
        private readonly ExecutionContext $context,
        private readonly ExecutionRuntime $runtime,
        ?ActivityContractResolver $activityContractResolver = null,
        ?WorkflowDefinitionLoader $workflowLoader = null,
    ) {
        $this->activityResolver = $activityContractResolver;
        $this->workflowLoader = $workflowLoader;
    }

    public static function wrap(ExecutionContext $context, ExecutionRuntime $runtime): self
    {
        return new self($context, $runtime);
    }

    /**
     * Registers a query handler callable for the given query type name.
     * Called by WorkflowDefinitionLoader after instantiating the workflow.
     */
    public function registerQueryHandler(string $queryType, callable $handler): void
    {
        $this->queryHandlers[$queryType] = $handler;
    }

    /**
     * Calls a registered query handler and returns its result.
     *
     * @param array<mixed> $args
     *
     * @throws \InvalidArgumentException if no handler is registered for the query type
     */
    public function callQueryHandler(string $queryType, array $args = []): mixed
    {
        $handler = $this->queryHandlers[$queryType] ?? null;
        if (null === $handler) {
            throw new \InvalidArgumentException(\sprintf('No query handler registered for query type: %s', $queryType));
        }

        return $handler(...$args);
    }

    public function hasQueryHandler(string $queryType): bool
    {
        return isset($this->queryHandlers[$queryType]);
    }

    /**
     * @param Awaitable<mixed> $awaitable
     */
    public function await(Awaitable $awaitable): mixed
    {
        return $this->runtime->await($awaitable, $this->context);
    }

    /**
     * @param Awaitable<mixed> $awaitables
     *
     * @return array<int, mixed>
     */
    public function parallel(Awaitable ...$awaitables): array
    {
        $results = [];
        foreach ($awaitables as $awaitable) {
            $results[] = $this->await($awaitable);
        }

        return $results;
    }

    /**
     * @param Awaitable<mixed> $awaitables
     *
     * @return array<int, mixed>
     */
    public function all(Awaitable ...$awaitables): array
    {
        return $this->parallel(...$awaitables);
    }

    /**
     * @param Awaitable<mixed> $awaitables
     */
    public function any(Awaitable ...$awaitables): mixed
    {
        $inner = new AnyAwaitable($awaitables);
        $composite = $inner;
        foreach ($awaitables as $a) {
            // Activités ET minuteurs perdants sont annulables : sans le minuteur, un
            // `any(timer, timer)` laissait une échéance morte réveiller l'exécution.
            if ($a instanceof ActivityAwaitable || $a instanceof TimerAwaitable) {
                $composite = new CancellingAnyAwaitable($this->context, $inner, $awaitables);
                break;
            }
        }

        return $this->await($composite);
    }

    /**
     * @param Awaitable<mixed> $awaitables
     */
    public function race(Awaitable ...$awaitables): mixed
    {
        return $this->any(...$awaitables);
    }

    public function sleep(Duration|\DateInterval|\DateTimeInterface|int|float $duration, string $timerSummary = ''): void
    {
        $this->await($this->timer($duration, $timerSummary));
    }

    /**
     * Minuteur, à attendre avec {@see await()} ou à composer avec {@see any()} / {@see parallel()}.
     *
     * Rend un awaitable comme {@see activity()} : les deux méthodes de cette façade se
     * comportent pareil. Pour simplement attendre une échéance, {@see sleep()} le dit dans son
     * nom.
     *
     * Le minuteur perdant d'un {@see any()} est annulé
     * ({@see \Gplanchat\Durable\Event\TimerCancelled}) pour ne pas réveiller l'exécution sur
     * une échéance morte.
     *
     * @return Awaitable<mixed>
     */
    public function timer(Duration|\DateInterval|\DateTimeInterface|int|float $duration, string $timerSummary = ''): Awaitable
    {
        return $this->context->timer(Duration::from($duration), $timerSummary);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $closure
     *
     * @return T
     */
    public function sideEffect(\Closure $closure): mixed
    {
        return $this->await($this->context->sideEffect($closure));
    }

    /**
     * Planifie un workflow enfant sans l’attendre ; à combiner avec {@see all} / {@see parallel} pour plusieurs enfants en parallèle.
     *
     * @param array<string, mixed> $input
     *
     * @return Awaitable<mixed>
     */
    public function scheduleChildWorkflow(string $childWorkflowType, array $input = [], ?ChildWorkflowOptions $options = null): Awaitable
    {
        return $this->context->executeChildWorkflow($childWorkflowType, $input, $options);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function executeChildWorkflow(string $childWorkflowType, array $input = [], ?ChildWorkflowOptions $options = null): mixed
    {
        return $this->await($this->scheduleChildWorkflow($childWorkflowType, $input, $options));
    }

    /**
     * Retourne un stub typé pour un workflow enfant.
     *
     * @template TWorkflow of object
     *
     * @param class-string<TWorkflow> $workflowClass
     *
     * @return ChildWorkflowStub<TWorkflow>
     */
    public function childWorkflowStub(string $workflowClass, ?ChildWorkflowOptions $options = null): ChildWorkflowStub
    {
        $loader = $this->workflowLoader ?? new WorkflowDefinitionLoader();

        return new ChildWorkflowStub($this, $workflowClass, $loader, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitSignal(string $signalName): array
    {
        return $this->await($this->context->waitSignal($signalName));
    }

    public function waitUpdate(string $updateName): mixed
    {
        return $this->await($this->context->waitUpdate($updateName));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function activity(string $name, array $payload = [], ?ActivityOptions $options = null): Awaitable
    {
        return $this->context->activity($name, $payload, $options);
    }

    /**
     * Retourne un stub typé pour le contrat d'activité.
     *
     * @template TActivity of object
     *
     * @param class-string<TActivity> $contractClass
     *
     * @return ActivityStub<TActivity>
     */
    public function activityStub(string $contractClass, ?ActivityOptions $options = null): ActivityStub
    {
        $resolver = $this->activityResolver ?? new ActivityContractResolver(null);

        return new ActivityStub($this, $contractClass, $resolver, $options);
    }

    /**
     * @return Awaitable<mixed>
     */
    public function async(mixed $value): Awaitable
    {
        return Deferred::resolved($value);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ContinueAsNewRequested
     */
    public function continueAsNew(string $workflowType, array $payload = [], ?ContinueAsNewOptions $options = null): never
    {
        $this->context->continueAsNew($workflowType, $payload, $options);
    }

    public function executionId(): string
    {
        return $this->context->executionId();
    }
}
