<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\ContextActivityScheduler;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Awaitable\CancellingCompositeAwaitable;
use Gplanchat\Durable\Awaitable\ConditionAwaitable;
use Gplanchat\Durable\Awaitable\QuorumAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\ContextNexusOperationScheduler;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\Workflow\ContextChildWorkflowScheduler;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * The per-execution facade: it wraps ExecutionContext and ExecutionRuntime.
 * The only workflow API on the application side — no free functions, no TLS scope.
 */
final class WorkflowEnvironment
{
    private ?ActivityContractResolver $activityResolver = null;

    private ?WorkflowDefinitionLoader $workflowLoader = null;

    private ?NexusContractResolver $nexusResolver = null;

    /** @var array<string, callable> signal name → handler */
    private array $signalHandlers = [];

    /** @var array<string, callable> update name → handler */
    private array $updateHandlers = [];

    public function __construct(
        private readonly ExecutionContext $context,
        private readonly ExecutionRuntime $runtime,
        ?ActivityContractResolver $activityContractResolver = null,
        ?WorkflowDefinitionLoader $workflowLoader = null,
        // Last: the Nexus resolver is only useful to `nexusStub()`, and adding it anywhere else
        // would shift positional arguments the engine already passes.
        ?NexusContractResolver $nexusContractResolver = null,
    ) {
        $this->activityResolver = $activityContractResolver;
        $this->workflowLoader = $workflowLoader;
        $this->nexusResolver = $nexusContractResolver;
    }

    public static function wrap(ExecutionContext $context, ExecutionRuntime $runtime): self
    {
        return new self($context, $runtime);
    }

    /**
     * Registers a signal's handler.
     *
     * The declarative form is {@see \Gplanchat\Durable\Attribute\SignalMethod}, which the loader
     * translates into this call. The imperative form is not a fallback: a workflow written as a
     * callable carries no attribute, and that is how most of this component's tests are written.
     *
     * Queries, on the other hand, are no longer registered here. The difference is not one of
     * principle but of place: a signal is dispatched inside this class, during {@see await()},
     * through `dispatch()`; a query is read by the worker, outside the fiber, from
     * {@see \Gplanchat\Durable\Workflow\QueryHandlerRegistry}, which the execution context
     * carries. What a workflow has no business reaching is what the engine reads without it.
     *
     * The handler receives the signal's payload and mutates the state the body observes, usually
     * through a condition passed to {@see await()}.
     */
    public function onSignal(\BackedEnum|string $signalName, callable $handler): void
    {
        $this->signalHandlers[self::messageName($signalName)] = $handler;
    }

    public function hasSignalHandler(\BackedEnum|string $signalName): bool
    {
        return isset($this->signalHandlers[self::messageName($signalName)]);
    }

    /**
     * Registers an update's handler.
     *
     * What differs from a signal is the return value: it is the answer the caller receives. A
     * handler that throws fails the update, not the execution.
     */
    public function onUpdate(\BackedEnum|string $updateName, callable $handler): void
    {
        $this->updateHandlers[self::messageName($updateName)] = $handler;
    }

    public function hasUpdateHandler(\BackedEnum|string $updateName): bool
    {
        return isset($this->updateHandlers[self::messageName($updateName)]);
    }

    private static function messageName(\BackedEnum|string $name): string
    {
        return $name instanceof \BackedEnum ? (string) $name->value : $name;
    }

    /**
     * Waits for an awaitable to settle, optionally under a deadline.
     *
     * The default deadline is {@see Duration::infinity()}: an unbounded wait lasts as long as it
     * takes. It is a domain value and not a `null` — it compares, travels and computes like any
     * other duration, so a caller composing its own deadline has no special case left to write for
     * "no bound".
     *
     * Under a finite deadline the wait is bounded: if the deadline elapses first, it throws
     * {@see DeadlineExceededException} rather than returning a value — `null` is an answer bounded
     * work is entitled to return (ADR DUR032).
     *
     * The deadline cancels what it bounded, and settling cancels the deadline. Cancelling an
     * activity stays *best effort*: the workflow will no longer be woken by it, but the attempt in
     * flight may carry on server-side — Temporal receives a cancellation *request*
     * ({@see \Gplanchat\Durable\Activity\ActivityCancellationType}).
     *
     * A `Closure` is accepted in place of an awaitable: it is a **condition** on the workflow's
     * state, and the execution resumes as soon as it holds. It must be a function of the workflow's
     * state alone — whatever a replay does not reproduce is recorded first with
     * {@see sideEffect()}. Beware that `fn()` captures by value: a condition on a local variable is
     * written `function () use (&$…)`.
     *
     * @param Awaitable<mixed>|\Closure(): bool $awaitable
     *
     * @throws DeadlineExceededException if the deadline elapses before the wait settles
     */
    public function await(Awaitable|\Closure $awaitable, Duration|\DateInterval|\DateTimeInterface|int|float|null $deadline = null): mixed
    {
        // A condition comes in through the same door as everything else: `await()` is the only
        // wait there is, and the awaitable contract is already exactly a predicate.
        if ($awaitable instanceof \Closure) {
            $awaitable = new ConditionAwaitable($awaitable);
        }

        $deadline = null === $deadline ? Duration::infinity() : Duration::from($deadline);

        // An infinite deadline schedules no timer: the only divergence between the two paths, and
        // an irreducible one — a timer that never fires would be one more command in the history,
        // for a wake-up that never comes.
        if ($deadline->isInfinite()) {
            $this->applyMessagesUntil($awaitable, null);

            return $this->runtime->await($awaitable, $this->context);
        }

        $timer = $this->timer($deadline, 'deadline');
        // The deadline bounds how far messages are applied: the DUR032 rule lives here, and not in
        // any reading of the history.
        $this->applyMessagesUntil(
            $awaitable,
            $timer instanceof TimerAwaitable ? $this->context->timerCompletionPosition($timer->timerId()) : null,
        );

        return $this->awaitUnderDeadline($awaitable, $timer, $deadline, self::describe($awaitable));
    }

    /**
     * Applies the recorded messages, one at a time, until the wait settles.
     *
     * This is the interleaving loop: each message is applied, its handler called, then the wait
     * tested again before moving to the next. Doing it in one block would be enough on the first
     * pass and would give the opposite verdict on replay — a message recorded after a deadline
     * fired would settle the condition that deadline had already decided.
     *
     * It is driven here, before the composite reaches the runtime: `isSettled()` on a composite
     * returns true as soon as one branch is settled, and the timer settled from the history would
     * short-circuit everything before a single message had been applied.
     *
     * @param Awaitable<mixed> $awaitable
     */
    private function applyMessagesUntil(Awaitable $awaitable, ?int $beforePosition): void
    {
        if (null === AwaitableInspector::describeCondition($awaitable)) {
            // Nothing that depends on the workflow's state: no message to apply to decide it.
            return;
        }

        while (!$awaitable->isSettled()) {
            $message = $this->context->nextMessage($beforePosition);
            if (null === $message) {
                return;
            }

            $this->dispatch($message);
        }
    }

    /**
     * @param array{kind: string, name: string, payload: array<string, mixed>, pending: \Gplanchat\Durable\Workflow\PendingUpdate|null} $message
     */
    private function dispatch(array $message): void
    {
        $handlers = 'update' === $message['kind'] ? $this->updateHandlers : $this->signalHandlers;
        $handler = $handlers[$message['name']] ?? null;
        if (null === $handler) {
            // A message nobody awaits is recorded and ignored, not an error.
            return;
        }

        $pending = $message['pending'];
        if (null === $pending) {
            // Replayed from the journal: the handler runs again to rebuild the state, but the
            // outcome already recorded stays the one the caller received.
            try {
                $handler($message['payload']);
            } catch (\Throwable $e) {
                if ('update' !== $message['kind']) {
                    throw $e;
                }
                // An update that had failed fails again on replay, and rightly so: its failure has
                // already gone out to the caller. Rethrowing here would fail an execution the
                // original had left alive.
            }

            return;
        }

        $pending->handled = true;

        try {
            $pending->result = $handler($message['payload']);
        } catch (\Throwable $e) {
            // The update fails, not the execution: the workflow carries on.
            $pending->failure = FailureEnvelope::fromThrowable($e);
        }

        // Recorded here, and not by the caller of the pass: the record has to precede what the
        // workflow does in response, or a replay would apply them the other way round. It is the
        // order Temporal produces — the acceptance before the workflow's commands.
        $this->context->recordUpdateHandled($message['name'], $message['payload'], $pending->result, $pending->failure);
    }

    /**
     * The race between the work and its deadline, whose verdict is read back from the branches and
     * not from the winning value: `any()` returns the value of the first *declared* branch that
     * settled, and the history can hold two (the signal recorded before the timer fired, both
     * present on replay). The verdict has to come from the journal.
     *
     * @param Awaitable<mixed> $awaitable
     * @param Awaitable<mixed> $timer
     *
     * @throws DeadlineExceededException
     */
    private function awaitUnderDeadline(Awaitable $awaitable, Awaitable $timer, Duration $deadline, string $awaited): mixed
    {
        // The composite stays a CancellingCompositeAwaitable wrapping an AnyAwaitable: that is what
        // AwaitableInspector::waitsOnTimer() walks, and without it no wake-up is scheduled — the
        // deadline would never leave.
        $composite = new CancellingCompositeAwaitable($this->context, new AnyAwaitable([$awaitable, $timer]));

        try {
            $this->runtime->await($composite, $this->context);
        } catch (WorkflowSuspendedException|ContinueAsNewRequested $e) {
            // Control flow, not a branch failure: swallowing it would turn a suspension into a hard
            // failure, a long way from its cause.
            throw $e;
        } catch (\Throwable) {
            // A branch failing decides nothing: the branches are read back below.
        }

        $failure = null;
        if ($awaitable->isSettled()) {
            try {
                return $awaitable->getResult();
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if ($failure instanceof WorkflowCancelledFailure) {
            throw $failure;
        }

        if ($timer->isSettled()) {
            // Relève si le minuteur a été rejeté (annulation du workflow) : ce n'est pas une échéance.
            $timer->getResult();

            throw new DeadlineExceededException($deadline, $awaited);
        }

        if (null !== $failure) {
            throw $failure;
        }

        throw new \LogicException('Deadline race settled without a settled branch.');
    }

    /**
     * @param Awaitable<mixed> $awaitable
     */
    private static function describe(Awaitable $awaitable): string
    {
        return match (true) {
            $awaitable instanceof ActivityAwaitable => 'activity ' . $awaitable->activityId(),
            $awaitable instanceof TimerAwaitable => 'timer ' . $awaitable->timerId(),
            $awaitable instanceof ConditionAwaitable => $awaitable->describe(),
            default => (new \ReflectionClass($awaitable))->getShortName(),
        };
    }

    /**
     * Un assemblage réglé quand **tous** ses membres ont abouti, dans l'ordre de déclaration
     * (ADR DUR033).
     *
     * Rend un {@see Awaitable} et n'attend rien : c'est {@see await()} qui bloque, et lui seul.
     * Un assembleur qui attendrait à votre place ne se composerait pas — impossible d'en border
     * un par une échéance, ou d'en mettre un dans un autre, alors que ce sont là les deux seules
     * raisons de l'écrire.
     *
     *     [$a, $b] = $env->await($env->all($x, $y));
     *     $env->await($env->all($x, $y), Duration::seconds(30));
     *
     * L'échec d'un membre est l'échec de l'ensemble : le quorum est plein, donc plus rien ne
     * peut l'atteindre dès qu'un seul manque.
     *
     * @param Awaitable<mixed> $awaitables
     *
     * @return Awaitable<mixed>
     */
    public function all(Awaitable ...$awaitables): Awaitable
    {
        return new QuorumAwaitable($awaitables, \count($awaitables));
    }

    /**
     * Un assemblage réglé dès qu'**un** membre l'est, quel qu'en soit le sort, et qui rend la
     * valeur de ce gagnant.
     *
     * Les branches perdantes encore en vol — activités comme minuteurs — sont retirées de la
     * file : sans cela, un `any(timer, timer)` laissait une échéance morte réveiller
     * l'exécution.
     *
     * @param Awaitable<mixed> $awaitables
     *
     * @return Awaitable<mixed>
     */
    public function any(Awaitable ...$awaitables): Awaitable
    {
        if ([] === $awaitables) {
            throw new \InvalidArgumentException('any() needs at least one awaitable: a race with no runner never settles.');
        }

        return new CancellingCompositeAwaitable($this->context, new AnyAwaitable($awaitables));
    }

    /**
     * Un assemblage réglé quand **$count** membres ont abouti — trois prix sur huit suffisent à
     * décider, et les cinq autres ne coûtent plus que leur latence.
     *
     *     $prices = $env->await($env->some(3, ...$providers), Duration::seconds(2));
     *
     * Rend les résultats des $count premiers aboutis, **indexés par leur position de
     * déclaration** : c'est ainsi que l'appelant sait lesquels ont répondu. Les membres encore
     * en course quand le quorum tombe sont retirés de la file.
     *
     * Seuls les membres **aboutis** comptent ; un membre qui échoue ne rapproche pas du quorum,
     * il l'éloigne. Quand il en reste trop peu pour l'atteindre, l'attente est réglée par le
     * premier échec plutôt que de ne se régler jamais.
     *
     * @param Awaitable<mixed> $awaitables
     *
     * @return Awaitable<mixed>
     */
    public function some(int $count, Awaitable ...$awaitables): Awaitable
    {
        return new CancellingCompositeAwaitable($this->context, new QuorumAwaitable($awaitables, $count));
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
        $duration = Duration::from($duration);
        if ($duration->isInfinite()) {
            throw new \InvalidArgumentException('A timer cannot be infinite: it would be a command in history for a wake-up that never comes. An unbounded wait is await() without a deadline.');
        }

        return $this->context->timer($duration, $timerSummary);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $closure
     *
     * @return T
     */
    /**
     * Déclare que le comportement de ce workflow a changé ici, et rend celui qui concerne
     * l'exécution en cours.
     *
     * ```php
     * if ($this->environment->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1) === ChangePoint::DEFAULT_VERSION) {
     *     $total = $this->await($this->facturation->totalSansRemise($panier));
     * } else {
     *     $total = $this->await($this->facturation->totalAvecRemise($panier));
     * }
     * ```
     *
     * La réponse est figée à la première rencontre et relue du journal ensuite : une exécution
     * déjà partie garde son comportement quoi qu'on déploie après elle, et c'est le seul endroit
     * où la garde de divergence (DUR042) accepte que le code s'écarte de l'historique.
     *
     * Deux points de changement sont indépendants : une exécution peut être du vieux côté de l'un
     * et du neuf côté de l'autre.
     *
     * @param string $changeId     le nom de ce point, stable dans le temps — il vit dans l'historique
     * @param int    $minSupported la plus ancienne version que ce code sait encore jouer
     * @param int    $maxSupported la plus récente, celle qu'une exécution neuve prendra
     */
    public function version(string $changeId, int $minSupported, int $maxSupported): int
    {
        return $this->context->version($changeId, $minSupported, $maxSupported);
    }

    public function sideEffect(\Closure $closure): mixed
    {
        return $this->await($this->context->sideEffect($closure));
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

        return new ChildWorkflowStub(new ContextChildWorkflowScheduler($this->context), $workflowClass, $loader, $options);
    }


    /**
     * Appelle une opération Nexus : une opération servie par une autre équipe, un autre namespace,
     * un autre déploiement — et qu'on attend comme une activité.
     *
     * Les trois noms sont des objets-valeurs parce que le serveur ne protège que le premier :
     * sondé, il refuse net un endpoint mal formé, et accepte sans broncher un service ou une
     * opération vides, blancs ou truffés de caractères de contrôle. Une opération ainsi nommée est
     * enregistrée telle quelle puis attend un gestionnaire qui ne correspondra jamais.
     *
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     *
     * @throws \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException si le backend configuré ne sait pas router l'appel
     */
    public function nexusOperation(
        NexusEndpoint|string $endpoint,
        NexusService|string $service,
        NexusOperationName|string $operation,
        array $payload = [],
        ?NexusOperationTimeouts $timeouts = null,
    ): Awaitable {
        return $this->context->nexusOperation(
            NexusEndpoint::from($endpoint),
            NexusService::from($service),
            NexusOperationName::from($operation),
            $payload,
            $timeouts,
        );
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

        return new ActivityStub(new ContextActivityScheduler($this->context), $contractClass, $resolver, $options);
    }

    /**
     * Un proxy typé vers les opérations d'un contrat Nexus servi à `$endpoint`.
     *
     * L'endpoint est un paramètre du stub et non du contrat : il dit *où* le service est servi, ce
     * qui relève du déploiement et change d'un environnement à l'autre, quand le contrat ne change
     * pas.
     *
     * @template TContract of object
     *
     * @param class-string<TContract> $contractClass
     *
     * @return NexusStub<TContract>
     */
    public function nexusStub(
        string $contractClass,
        NexusEndpoint|string $endpoint,
        ?NexusOperationTimeouts $timeouts = null,
    ): NexusStub {
        return new NexusStub(
            new ContextNexusOperationScheduler($this->context),
            $contractClass,
            $this->nexusResolver ?? new NexusContractResolver(null),
            NexusEndpoint::from($endpoint),
            $timeouts,
        );
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
