<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;

/**
 * Le bundle Symfony enregistre toujours la suspension sur await non résolu (6ᵉ argument à true).
 * Les tests peuvent passer false pour simuler un drain synchrone dans le même processus.
 */
final class ExecutionRuntime
{
    /** @var callable(): float */
    private $clock;

    private ?ActivityMessageProcessor $activityMessageProcessor = null;

    /**
     * Budget de temps du drain synchrone : c'est un harnais en ligne, pas un worker — il ne peut
     * pas dormir indéfiniment sur le backoff d'une activité qui échoue toujours.
     */
    public const DEFAULT_DRAIN_BUDGET_SECONDS = 5.0;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        ?callable $clock = null,
        private readonly bool $distributed = false,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * @param Awaitable<mixed> $awaitable
     */
    public function await(Awaitable $awaitable, ExecutionContext $context): mixed
    {
        if ($awaitable->isSettled()) {
            return $awaitable->getResult();
        }

        if ($this->distributed) {
            if (null !== \Fiber::getCurrent()) {
                \Fiber::suspend($awaitable);

                // Resumed by ExecutionEngine fiber loop after the awaitable was settled
                return $awaitable->getResult();
            }

            // Called outside of a fiber (backward-compatibility path for non-fiber callers)
            throw new WorkflowSuspendedException(\sprintf('Workflow %s suspended (distributed mode)', $context->executionId()), 0, null, $this->awaitableShouldDispatchResume($awaitable), AwaitableInspector::waitsOnTimer($awaitable));
        }

        // Synchronous in-memory drain (distributed=false)
        while (!$awaitable->isSettled()) {
            $this->drainActivityQueueOnce($context);
            $this->checkTimers($context);
        }

        return $awaitable->getResult();
    }

    public function checkTimers(ExecutionContext $context): void
    {
        $now = ($this->clock)();
        $scheduledIds = [];
        $completedIds = [];
        $cancelledIds = [];
        foreach ($this->eventStore->readStream($context->executionId()) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = ['id' => $event->timerId(), 'at' => $event->scheduledAt()];
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
            if ($event instanceof TimerCancelled) {
                $cancelledIds[$event->timerId()] = true;
            }
        }

        foreach ($scheduledIds as $info) {
            if (isset($completedIds[$info['id']]) || isset($cancelledIds[$info['id']])) {
                continue;
            }
            if ($now >= $info['at']) {
                $this->eventStore->append(new TimerCompleted($context->executionId(), $info['id']));
                $completedIds[$info['id']] = true;
                $context->resolveTimer($info['id']);
            }
        }
    }

    /**
     * Horloge utilisée par {@see checkTimers()} et par le calcul de délai Messenger pour les minuteurs.
     */
    public function nowSeconds(): float
    {
        return ($this->clock)();
    }

    /**
     * Exécute **une** tentative d'activité en ligne, puis règle l'awaitable du contexte.
     *
     * Le travail lui-même est délégué à {@see ActivityMessageProcessor}, le même que le worker
     * Messenger : timeouts, marqueurs worker, politique de retry et annulation par heartbeat
     * étaient auparavant absents de ce chemin, si bien que le harness de test public
     * ({@see \Gplanchat\Durable\Testing\WorkflowTestEnvironment}) ne reproduisait pas le
     * comportement de production. Seule la résolution de l'awaitable reste ici : elle n'existe
     * que dans le drain en ligne, où le fiber du workflow vit dans le même processus.
     *
     * @return bool false si la file n'avait aucun message prêt
     */
    public function drainActivityQueueOnce(ExecutionContext $context): bool
    {
        $message = $this->activityTransport->dequeue();
        if (null === $message) {
            return false;
        }

        $this->activityMessageProcessor()->process($message);

        $outcome = ActivityEventJournal::lastTerminalOutcome(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
        );

        switch (true) {
            case $outcome instanceof ActivityCompleted:
                $context->resolveActivity($message->activityId, $outcome->result());
                break;
            case $outcome instanceof ActivityFailed:
                $context->rejectActivity($message->activityId, DurableActivityFailedException::toThrowable($outcome));
                break;
            case $outcome instanceof ActivityCatastrophicFailure:
                $context->rejectActivity($message->activityId, new DurableCatastrophicActivityFailureException($outcome));
                break;
            case $outcome instanceof ActivityCancelled:
                $context->rejectActivity($message->activityId, new ActivitySupersededException($message->activityId, $outcome->reason()));
                break;
            default:
                // Aucune issue terminale : une retentative est en file, on la traitera au tour suivant.
                break;
        }

        return true;
    }

    private function activityMessageProcessor(): ActivityMessageProcessor
    {
        return $this->activityMessageProcessor ??= new ActivityMessageProcessor(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            new NullWorkflowResumeDispatcher(),
            new NullActivityHeartbeatSender(),
            $this->maxActivityRetries,
            $this->workflowExecutionObserver,
        );
    }

    /**
     * Draine la file jusqu'à épuisement, **retentatives différées comprises**.
     *
     * `isEmpty()` ne signale que l'absence de message *prêt* : boucler dessus concluait « plus
     * rien à faire » alors qu'une retentative était planifiée quelques secondes plus tard, si
     * bien que la politique de retry ne s'appliquait pas du tout dans le harness de test.
     *
     * ponytail: le backoff est attendu pour de vrai — ce drain est synchrone et dans le même
     * processus. Une horloge virtuelle partagée avec le transport permettrait de l'avancer.
     */
    public function runUntilIdle(ExecutionContext $context, ?float $budgetSeconds = null): void
    {
        $deadline = microtime(true) + ($budgetSeconds ?? self::DEFAULT_DRAIN_BUDGET_SECONDS);

        while (null !== ($dueAt = $this->activityTransport->nextDueAt())) {
            // Les tentatives sont illimitées par défaut (sémantique Temporal) : une activité
            // durablement en échec ferait tourner ce drain sans fin. En production le transport
            // Messenger rend la main entre deux tentatives ; ici on s'arrête, et l'appelant
            // signale une exécution qui n'avance plus.
            if ($dueAt > $deadline || microtime(true) >= $deadline) {
                return;
            }

            $wait = $dueAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000.0));
            }
            if (!$this->drainActivityQueueOnce($context)) {
                return;
            }
        }
    }

    public function getActivityTransport(): ActivityTransportInterface
    {
        return $this->activityTransport;
    }

    /**
     * Timer : {@see ResumeWorkflowHandler} envoie {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage} (pas un resume direct).
     * Activité : faux — {@see ActivityMessageProcessor} appelle {@see \Gplanchat\Durable\Port\WorkflowResumeDispatcher::dispatchResume}
     * à la fin de l’activité ; un {@code dispatchResume} depuis le handler workflow avec transport **sync/in-memory** bouclerait à l’infini.
     * Signal / update : seuls {@see DeliverWorkflowSignalHandler} etc. doivent relancer.
     *
     * @param Awaitable<mixed> $awaitable
     */
    private function awaitableShouldDispatchResume(Awaitable $awaitable): bool
    {
        return AwaitableInspector::waitsOnTimer($awaitable);
    }
}
