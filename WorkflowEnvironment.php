<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Awaitable\CancellingCompositeAwaitable;
use Gplanchat\Durable\Awaitable\ConditionAwaitable;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Awaitable\QuorumAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Failure\FailureEnvelope;
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

    /** @var array<string, callable> signal name → handler */
    private array $signalHandlers = [];

    /** @var array<string, callable> update name → handler */
    private array $updateHandlers = [];

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
     * Enregistre le handler d'un signal, comme {@see registerQueryHandler()} le fait d'une query.
     *
     * La forme déclarative est {@see \Gplanchat\Durable\Attribute\SignalMethod}, que le
     * chargeur traduit en cet appel. La forme impérative n'est pas un pis-aller : un workflow
     * écrit comme une callable ne porte pas d'attribut, et c'est ainsi que s'écrit la majorité
     * des tests de ce composant.
     *
     * Le handler reçoit la charge utile du signal et mute l'état que le corps observe, en
     * général à travers une condition passée à {@see await()}.
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
     * Enregistre le handler d'un update.
     *
     * La différence avec un signal tient dans la valeur de retour : c'est la réponse que
     * l'appelant reçoit. Un handler qui relève fait échouer l'update, pas l'exécution.
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
     * Attend le règlement d'un awaitable, éventuellement sous échéance.
     *
     * L'échéance par défaut est {@see Duration::infinity()} : une attente non bornée dure ce
     * qu'il faut. C'est une valeur du domaine et non un `null` — elle se compare, se transporte
     * et se calcule comme n'importe quelle autre durée, si bien qu'un appelant qui compose son
     * échéance n'a plus de cas particulier à écrire pour « pas de borne ».
     *
     * Sous une échéance finie, l'attente est bornée : si l'échéance s'écoule d'abord, elle relève
     * {@see DeadlineExceededException} plutôt que de rendre une valeur — `null` est une réponse
     * qu'un travail borné a le droit de rendre (ADR DUR032).
     *
     * L'échéance annule ce qu'elle bornait, et le règlement annule l'échéance. L'annulation
     * d'une activité reste *best effort* : le workflow ne sera plus réveillé par elle, mais la
     * tentative en cours peut continuer côté serveur — Temporal reçoit une *demande*
     * d'annulation ({@see \Gplanchat\Durable\Activity\ActivityCancellationType}).
     *
     * Une `Closure` est acceptée à la place d'un awaitable : c'est une **condition** sur l'état
     * du workflow, et l'exécution repart dès qu'elle est vraie. Elle doit être fonction du seul
     * état du workflow — ce qu'un replay ne reproduit pas se consigne d'abord avec
     * {@see sideEffect()}. Attention, `fn()` capture par valeur : une condition sur une variable
     * locale s'écrit `function () use (&$…)`.
     *
     * @param Awaitable<mixed>|\Closure(): bool $awaitable
     *
     * @throws DeadlineExceededException si l'échéance s'écoule avant le règlement
     */
    public function await(Awaitable|\Closure $awaitable, Duration|\DateInterval|\DateTimeInterface|int|float|null $deadline = null): mixed
    {
        // Une condition entre par la même porte que tout le reste : `await()` est la seule
        // attente, et le contrat d'awaitable est déjà exactement un prédicat.
        if ($awaitable instanceof \Closure) {
            $awaitable = new ConditionAwaitable($awaitable);
        }

        $deadline = null === $deadline ? Duration::infinity() : Duration::from($deadline);

        // Une échéance infinie ne planifie pas de minuteur : le seul écart entre les deux
        // chemins, et il est irréductible — un minuteur qui ne tire jamais serait une commande
        // de plus dans l'historique, pour un réveil qui n'arrive pas.
        if ($deadline->isInfinite()) {
            $this->applyMessagesUntil($awaitable, null);

            return $this->runtime->await($awaitable, $this->context);
        }

        $timer = $this->timer($deadline, 'deadline');
        // L'échéance borne l'application des messages : c'est ici, et pas dans une lecture
        // d'historique, que vit la règle de DUR032.
        $this->applyMessagesUntil(
            $awaitable,
            $timer instanceof TimerAwaitable ? $this->context->timerCompletionPosition($timer->timerId()) : null,
        );

        return $this->awaitUnderDeadline($awaitable, $timer, $deadline, self::describe($awaitable));
    }

    /**
     * Applique les messages enregistrés, un par un, jusqu'à ce que l'attente soit réglée.
     *
     * C'est la boucle d'entrelacement : chaque message est appliqué, son handler appelé, puis
     * l'attente retestée avant de passer au suivant. La faire d'un bloc suffirait au premier
     * passage et donnerait le verdict inverse au replay — un message enregistré après le tir
     * d'une échéance réglerait la condition que cette échéance avait déjà tranchée.
     *
     * Elle est pilotée ici, avant que le composite n'atteigne le runtime : `isSettled()` d'un
     * composite rend vrai dès qu'une branche l'est, et le minuteur réglé par l'historique
     * court-circuiterait tout avant qu'un seul message n'ait été appliqué.
     *
     * @param Awaitable<mixed> $awaitable
     */
    private function applyMessagesUntil(Awaitable $awaitable, ?int $beforePosition): void
    {
        if (null === AwaitableInspector::describeCondition($awaitable)) {
            // Rien qui dépende de l'état du workflow : aucun message à appliquer pour trancher.
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
            // Un message que personne n'attend est enregistré et ignoré, pas une erreur.
            return;
        }

        $pending = $message['pending'];
        if (null === $pending) {
            // Rejoué depuis le journal : le handler retourne pour reconstruire l'état, mais
            // l'issue déjà consignée reste celle que l'appelant a reçue.
            try {
                $handler($message['payload']);
            } catch (\Throwable $e) {
                if ('update' !== $message['kind']) {
                    throw $e;
                }
                // Un update qui avait échoué échoue encore au replay, et c'est normal : sa
                // défaillance est déjà partie chez l'appelant. La relever ici ferait échouer une
                // exécution que l'original avait laissée vivante.
            }

            return;
        }

        $pending->handled = true;

        try {
            $pending->result = $handler($message['payload']);
        } catch (\Throwable $e) {
            // L'update échoue, pas l'exécution : le workflow poursuit son chemin.
            $pending->failure = FailureEnvelope::fromThrowable($e);
        }
    }

    /**
     * Course entre le travail et son échéance, dont le verdict est relu des branches et non de
     * la valeur gagnante : `any()` rend la valeur de la première branche *déclarée* qui s'est
     * réglée, et l'historique peut en contenir deux (le signal enregistré avant le tir du
     * minuteur, tous deux présents au replay). Le verdict doit venir du journal.
     *ignal
     * @param Awaitable<mixed> $awaitable
     * @param Awaitable<mixed> $timer
     *
     * @throws DeadlineExceededException
     */
    private function awaitUnderDeadline(Awaitable $awaitable, Awaitable $timer, Duration $deadline, string $awaited): mixed
    {
        // Le composite reste un CancellingCompositeAwaitable enveloppant un AnyAwaitable :
        // c'est ce que traverse AwaitableInspector::waitsOnTimer(), et sans lui aucun réveil
        // n'est planifié — l'échéance ne partirait jamais.
        $composite = new CancellingCompositeAwaitable($this->context, new AnyAwaitable([$awaitable, $timer]));

        try {
            $this->runtime->await($composite, $this->context);
        } catch (WorkflowSuspendedException|ContinueAsNewRequested $e) {
            // Du contrôle de flux, pas un échec de branche : l'avaler transformerait une
            // suspension en défaillance dure, très loin de sa cause.
            throw $e;
        } catch (\Throwable) {
            // L'échec d'une branche ne décide de rien : les branches sont relues ci-dessous.
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
    public function sideEffect(\Closure $closure): mixed
    {
        return $this->await($this->context->sideEffect($closure));
    }

    /**
     * Planifie un workflow enfant sans l’attendre ; à combiner avec {@see all()} pour plusieurs enfants en parallèle.
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
