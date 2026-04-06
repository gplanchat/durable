<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\CancellingAnyAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Le bundle Symfony enregistre toujours la suspension sur await non résolu (6ᵉ argument à true).
 * Les tests peuvent passer false pour simuler un drain synchrone dans le même processus.
 */
final class ExecutionRuntime
{
    /** @var callable(): float */
    private $clock;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        ?callable $clock = null,
        private readonly bool $distributed = false,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
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
            throw new WorkflowSuspendedException(\sprintf('Workflow %s suspended (distributed mode)', $context->executionId()), 0, null, $this->awaitableShouldDispatchResume($awaitable), $awaitable instanceof TimerAwaitable);
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
        foreach ($this->eventStore->readStream($context->executionId()) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = ['id' => $event->timerId(), 'at' => $event->scheduledAt()];
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
        }

        foreach ($scheduledIds as $info) {
            if (isset($completedIds[$info['id']])) {
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

    public function drainActivityQueueOnce(ExecutionContext $context): void
    {
        $message = $this->activityTransport->dequeue();
        if (null === $message) {
            return;
        }

        $t0 = microtime(true);
        try {
            $result = $this->activityExecutor->execute($message->activityName, $message->payload);
            $duration = microtime(true) - $t0;
            $this->workflowExecutionObserver?->onActivityExecuted(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $duration,
                true,
                null,
            );
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $context->resolveActivity($message->activityId, $result);
        } catch (\Throwable $e) {
            $duration = microtime(true) - $t0;
            $this->workflowExecutionObserver?->onActivityExecuted(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $duration,
                false,
                $e::class,
            );
            if ($message->attempt() <= $this->maxActivityRetries) {
                $this->activityTransport->enqueue($message->withAttempt($message->attempt() + 1));
            } else {
                $failureEvent = ActivityFailureEventFactory::fromActivityThrowable(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $message->attempt(),
                    $e,
                );
                $this->eventStore->append($failureEvent);
                if ($failureEvent instanceof ActivityFailed) {
                    $context->rejectActivity($message->activityId, DurableActivityFailedException::toThrowable($failureEvent));
                } else {
                    $context->rejectActivity(
                        $message->activityId,
                        new DurableCatastrophicActivityFailureException($failureEvent, $e),
                    );
                }
            }
        }
    }

    public function runUntilIdle(ExecutionContext $context): void
    {
        while (!$this->activityTransport->isEmpty()) {
            $this->drainActivityQueueOnce($context);
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
        if ($awaitable instanceof ActivityAwaitable) {
            return false;
        }
        if ($awaitable instanceof TimerAwaitable) {
            return true;
        }
        if ($awaitable instanceof CancellingAnyAwaitable) {
            return $this->awaitableShouldDispatchResume($awaitable->innerAny());
        }
        if ($awaitable instanceof AnyAwaitable) {
            foreach ($awaitable->members() as $member) {
                if ($this->awaitableShouldDispatchResume($member)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
