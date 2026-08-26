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
use Gplanchat\Durable\Awaitable\CancellingCompositeAwaitable;
use Gplanchat\Durable\Awaitable\QuorumAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
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
     * @param Awaitable<mixed> $awaitable
     *
     * @throws DeadlineExceededException si l'échéance s'écoule avant le règlement
     */
    public function await(Awaitable $awaitable, Duration|\DateInterval|\DateTimeInterface|int|float|null $deadline = null): mixed
    {
        $deadline = null === $deadline ? Duration::infinity() : Duration::from($deadline);

        // Une échéance infinie ne planifie pas de minuteur : le seul écart entre les deux
        // chemins, et il est irréductible — un minuteur qui ne tire jamais serait une commande
        // de plus dans l'historique, pour un réveil qui n'arrive pas.
        if ($deadline->isInfinite()) {
            return $this->runtime->await($awaitable, $this->context);
        }

        return $this->awaitUnderDeadline(
            $awaitable,
            $this->timer($deadline, 'deadline'),
            $deadline,
            self::describe($awaitable),
        );
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
     * Attend un signal, éventuellement sous échéance.
     *
     * Sans échéance, l'attente est inchangée : elle ne se règle qu'à la livraison du signal.
     * Avec une échéance, un signal *enregistré après le tir* de celle-ci ne règle pas cette
     * attente-là — sinon un replay lirait le verdict inverse de l'exécution d'origine. Ce
     * signal reste disponible pour une attente ultérieure du même nom.
     *
     * Le nom se donne en {@see \BackedEnum} — une enum adossée à des chaînes énumère la surface
     * de signaux d'un workflow et fait relever la faute de frappe par le moteur de types plutôt
     * que par une attente qui ne se règle jamais. La chaîne nue reste acceptée : un signal
     * arrive de l'extérieur (curl, CLI Temporal, un autre langage), et cette frontière-là ne se
     * type pas (ADR DUR034).
     *
     * @return array<string, mixed>
     *
     * @throws DeadlineExceededException si l'échéance s'écoule avant la livraison
     */
    public function waitSignal(\BackedEnum|string $signalName, Duration|\DateInterval|\DateTimeInterface|int|float|null $deadline = null): array
    {
        $signalName = $signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName;
        $deadline = null === $deadline ? Duration::infinity() : Duration::from($deadline);

        if ($deadline->isInfinite()) {
            /** @var array<string, mixed> */
            return $this->await($this->context->waitSignal($signalName));
        }

        $timer = $this->timer($deadline, 'deadline: signal ' . $signalName);

        try {
            /** @var array<string, mixed> */
            return $this->awaitUnderDeadline(
                $this->context->waitSignal($signalName, $timer instanceof TimerAwaitable ? $timer->timerId() : null),
                $timer,
                $deadline,
                'signal ' . $signalName,
            );
        } catch (DeadlineExceededException $e) {
            // L'attente abandonnée n'a consommé aucun signal : elle rend son rang, faute de quoi
            // l'attente suivante du même nom chercherait le *deuxième* signal et manquerait
            // celui arrivé en retard.
            $this->context->releaseSignalWaitSlot();

            throw $e;
        }
    }

    public function waitUpdate(string $updateName): mixed
    {
        return $this->await($this->context->waitUpdate($updateName));
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
