<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Handler;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;

/**
 * Cron / message : fait progresser les timers d'un run puis relance si besoin.
 *
 * If no timers fire on this pass (because the transport delivered the message before
 * the scheduled time elapsed — typically the in-memory transport in tests ignores
 * DelayStamp), the handler re-dispatches the check message with a fresh delay so
 * the workflow eventually resumes once the timer actually expires.
 */
final class FireWorkflowTimersHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly WorkflowTimerDispatcher $timerDispatcher,
    ) {}

    public function __invoke(FireWorkflowTimersMessage $message): void
    {
        $context = new ExecutionContext(
            $message->executionId,
            new EventStoreHistorySource($this->eventStore, $message->executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->runtime->getActivityTransport(), $message->executionId),
            null,
        );

        $before = $this->countTimerCompleted($message->executionId);
        $this->runtime->checkTimers($context);
        $after = $this->countTimerCompleted($message->executionId);

        if ($after > $before) {
            $this->resumeDispatcher->dispatchResume($message->executionId);

            return;
        }

        // No timer fired yet: re-schedule the check so the workflow eventually resumes
        // once the timer delay actually elapses (needed when the transport delivered
        // the message earlier than expected, e.g. in-memory transport + DelayStamp).
        $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
            $this->eventStore,
            $message->executionId,
            $this->runtime->nowSeconds(),
        );

        if (null !== $ms) {
            $this->timerDispatcher->dispatchTimerFire($message->executionId, max(0, $ms));
        }
    }

    private function countTimerCompleted(string $executionId): int
    {
        $n = 0;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof TimerCompleted) {
                ++$n;
            }
        }

        return $n;
    }
}
