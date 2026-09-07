<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Translates a journal stream into a readable history.
 *
 * A single forward pass is enough to name the activities: the scheduling always precedes the
 * completion in recording order, so the name is known by the time it is needed. A completion
 * without its scheduling — purged journal, partial resumption — falls back to the identifier: an id
 * is worth more than a row without a name.
 */
final class JournalRunHistoryReader
{
    /**
     * The key of the action the execution is for itself. One only per history, and the first: it
     * is its start that opens the stream.
     */
    private const RUN_ACTION = 'workflow';

    public function __construct(
        private readonly EventStoreInterface $events,
    ) {}

    /**
     * @return list<WorkflowRunEvent>
     */
    public function read(string $runId, string $workflowName = ''): array
    {
        /** @var array<string, string> $activityNames */
        $activityNames = [];
        // The terminal events of a Nexus operation carry nothing but the `scheduledEventId`: the
        // identity — endpoint, service, operation — is written on the scheduling only. Same
        // constraint as for activities, same remedy.
        /** @var array<int, string> $nexusNames */
        $nexusNames = [];
        // Same constraint as for activities: only the scheduling knows the summary.
        /** @var array<string, string> $timerNames */
        $timerNames = [];
        /** @var array<string, string> $childNames */
        $childNames = [];
        $history = [];
        $sequence = 0;

        foreach ($this->events->readStreamWithRecordedAt($runId) as $entry) {
            $event = $entry['event'];
            $recordedAt = $entry['recordedAt'] ?? null;

            if ($event instanceof ActivityScheduled) {
                $activityNames[$event->activityId()] = $event->activityName();
            }

            if ($event instanceof ChildWorkflowScheduled) {
                $childNames[$event->childExecutionId()] = $event->childWorkflowType();
            }

            if ($event instanceof TimerScheduled) {
                $timerNames[$event->timerId()] = self::timerLabel($event, $recordedAt);
            }

            if ($event instanceof NexusOperationScheduled) {
                $nexusNames[$event->scheduledEventId()] = self::nexusLabel(
                    $event->endpoint(),
                    $event->service(),
                    $event->operation(),
                );
            }

            $history[] = new WorkflowRunEvent(
                ++$sequence,
                $recordedAt instanceof \DateTimeImmutable ? $recordedAt : new \DateTimeImmutable('@0'),
                self::kindOf($event),
                self::labelOf($event, $activityNames, $nexusNames, $timerNames, $childNames, $workflowName),
                // `payload()` is on the `Event` interface: it is the serialised shape the event
                // already gives itself in order to be written and then read back. Nothing to
                // translate, and nothing to choose either — filtering here would amount to
                // deciding in the operator's stead what deserves to be seen on the day something
                // goes wrong.
                $event->payload(),
                self::actionKeyOf($event),
                // Same rule as the Temporal bridge: the event by which the work begins for real.
                // Here there are only two of them, and `ExecutionStarted` is inert — it opens the
                // execution, so no interval precedes it.
                $event instanceof ActivityTaskStarted || $event instanceof ExecutionStarted,
                self::isFailure($event),
            );
        }

        return $history;
    }

    /**
     * The name of a timer: its summary if it has one, and **its delay in every case**.
     *
     * "TimerScheduled" names the class, and a summary on its own says why we are waiting without
     * saying for how long — yet the delay is what an operator comes to read, and deducing it would
     * require subtracting two timestamps from two rows.
     *
     * ⚠ `scheduledAt()` is the **deadline**, not the instant of the scheduling: the delay is
     * therefore the difference with the recording timestamp. Without that timestamp — a journal
     * that did not keep it — the timer stays without a delay rather than announcing one counted
     * from the Unix epoch, which would give "55 years" for a five-second wait.
     */
    private static function timerLabel(TimerScheduled $event, ?\DateTimeImmutable $recordedAt): string
    {
        $name = '' === $event->summary() ? 'timer' : $event->summary();
        if (!$recordedAt instanceof \DateTimeImmutable) {
            return $name;
        }

        $delay = $event->scheduledAt() - (float) $recordedAt->format('U.u');

        return $delay <= 0.0 ? $name : $name . ' (' . ReadableDuration::of($delay) . ')';
    }

    /**
     * What went wrong, and nothing else.
     *
     * ⚠ **A cancellation is not one.** `ActivityCancelled`, `TimerCancelled`,
     * `WorkflowExecutionCancelled` are outcomes requested by someone; painting them as breakdowns
     * would send people looking for an incident where there is only a decision, and red would stop
     * meaning anything at all. The Temporal bridge holds the same line, at the two suffixes
     * `_FAILED` and `_TIMED_OUT` — ⚠ and it writes `CANCELED` with a single "l" where the journal
     * writes `Cancelled`, which makes a rule written on one side only miss.
     */
    private static function isFailure(Event $event): bool
    {
        return $event instanceof ActivityFailed
            || $event instanceof ActivityTaskFailed
            || $event instanceof ActivityCatastrophicFailure
            || $event instanceof ChildWorkflowFailed
            || $event instanceof WorkflowExecutionFailed
            || $event instanceof NexusOperationFailed
            || $event instanceof NexusOperationTimedOut;
    }

    /**
     * The action the event is part of, or `null` when it is its own all by itself.
     *
     * The journal already correlates: an activity by its `activityId`, a timer by its `timerId`, a
     * Nexus operation by the identifier of its scheduling. There is nothing to invent here, just to
     * stop throwing the link away at translation time.
     */
    private static function actionKeyOf(Event $event): ?string
    {
        // The execution itself is an action: its start, its end, its cancellation. A signal
        // received or an update are not part of it — they are actions in their own right, and that
        // is why the list is written out rather than derived from the `Execution` kind.
        if ($event instanceof ExecutionStarted
            || $event instanceof ExecutionCompleted
            || $event instanceof WorkflowExecutionFailed
            || $event instanceof WorkflowExecutionCancelled
            || $event instanceof WorkflowCancellationRequested
            || $event instanceof WorkflowContinuedAsNew
        ) {
            return self::RUN_ACTION;
        }

        if ($event instanceof ChildWorkflowScheduled
            || $event instanceof ChildWorkflowCompleted
            || $event instanceof ChildWorkflowFailed
        ) {
            return 'child:' . $event->childExecutionId();
        }

        $activityId = self::activityIdOf($event);
        if (null !== $activityId) {
            return 'activity:' . $activityId;
        }

        if ($event instanceof TimerScheduled
            || $event instanceof TimerCompleted
            || $event instanceof TimerCancelled
        ) {
            return 'timer:' . $event->timerId();
        }

        if ($event instanceof NexusOperationScheduled) {
            return 'nexus:' . $event->scheduledEventId();
        }

        $scheduledEventId = self::nexusScheduledEventIdOf($event);

        return null === $scheduledEventId ? null : 'nexus:' . $scheduledEventId;
    }

    private static function kindOf(Event $event): WorkflowRunEventKind
    {
        return match (true) {
            $event instanceof ExecutionStarted,
            $event instanceof ExecutionCompleted,
            $event instanceof WorkflowExecutionFailed,
            $event instanceof WorkflowExecutionCancelled,
            $event instanceof WorkflowCancellationRequested,
            $event instanceof WorkflowContinuedAsNew => WorkflowRunEventKind::Execution,

            $event instanceof ActivityScheduled,
            $event instanceof ActivityCompleted,
            $event instanceof ActivityFailed,
            $event instanceof ActivityCancelled,
            $event instanceof ActivityCatastrophicFailure,
            $event instanceof ActivityTaskStarted,
            $event instanceof ActivityTaskCompleted,
            $event instanceof ActivityTaskFailed => WorkflowRunEventKind::Activity,

            $event instanceof NexusOperationScheduled,
            $event instanceof NexusOperationCompleted,
            $event instanceof NexusOperationFailed,
            $event instanceof NexusOperationTimedOut,
            $event instanceof NexusOperationCancelled => WorkflowRunEventKind::Nexus,

            $event instanceof WorkflowSignalReceived => WorkflowRunEventKind::Signal,
            $event instanceof WorkflowUpdateHandled => WorkflowRunEventKind::Update,

            default => WorkflowRunEventKind::Other,
        };
    }

    /**
     * @param array<string, string> $activityNames
     * @param array<int, string>    $nexusNames
     * @param array<string, string> $timerNames
     * @param array<string, string> $childNames
     */
    private static function labelOf(
        Event $event,
        array $activityNames,
        array $nexusNames,
        array $timerNames,
        array $childNames,
        string $workflowName,
    ): string {
        // A frieze row carries the name of its action, and the action of the execution is the
        // execution: "ExecutionStarted" names an event class, not what is running. The journal
        // does not know that name — it only has a stream — so the caller gives it to it.
        if ($event instanceof ExecutionStarted && '' !== $workflowName) {
            return $workflowName;
        }

        if ($event instanceof ChildWorkflowScheduled) {
            return $event->childWorkflowType();
        }

        if ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) {
            return $childNames[$event->childExecutionId()] ?? ('child ' . $event->childExecutionId());
        }

        if ($event instanceof NexusOperationScheduled) {
            return self::nexusLabel($event->endpoint(), $event->service(), $event->operation());
        }

        $scheduledEventId = self::nexusScheduledEventIdOf($event);
        if (null !== $scheduledEventId) {
            // Without the scheduling — a truncated journal, a partial read — it is better to
            // name the identifier than to return a label that designates nothing.
            return $nexusNames[$scheduledEventId] ?? ('nexus #' . $scheduledEventId);
        }

        if ($event instanceof TimerScheduled
            || $event instanceof TimerCompleted
            || $event instanceof TimerCancelled
        ) {
            // A frieze row carries the name of its action. "TimerScheduled" names the class, not
            // the wait: `timer 5s before retry` says what an operator came to read.
            return $timerNames[$event->timerId()] ?? ('timer ' . $event->timerId());
        }

        if ($event instanceof WorkflowSignalReceived) {
            return $event->signalName();
        }
        if ($event instanceof WorkflowUpdateHandled) {
            return $event->updateName();
        }

        $activityId = self::activityIdOf($event);
        if (null !== $activityId) {
            return $activityNames[$activityId] ?? $activityId;
        }

        return self::shortName($event);
    }

    private static function nexusLabel(string $endpoint, string $service, string $operation): string
    {
        return \sprintf('%s/%s/%s', $endpoint, $service, $operation);
    }

    private static function nexusScheduledEventIdOf(Event $event): ?int
    {
        return match (true) {
            $event instanceof NexusOperationCompleted,
            $event instanceof NexusOperationFailed,
            $event instanceof NexusOperationTimedOut,
            $event instanceof NexusOperationCancelled => $event->scheduledEventId(),
            default => null,
        };
    }

    private static function activityIdOf(Event $event): ?string
    {
        return match (true) {
            $event instanceof ActivityScheduled,
            $event instanceof ActivityCompleted,
            $event instanceof ActivityFailed,
            $event instanceof ActivityCancelled,
            $event instanceof ActivityCatastrophicFailure,
            $event instanceof ActivityTaskStarted,
            $event instanceof ActivityTaskCompleted,
            $event instanceof ActivityTaskFailed => $event->activityId(),
            default => null,
        };
    }

    /**
     * Readable fallback for everything that has no business name: `SideEffectRecorded` is worth
     * more than `Gplanchat\Durable\Event\SideEffectRecorded`, and far better than nothing.
     */
    private static function shortName(Event $event): string
    {
        $parts = explode('\\', $event::class);

        return end($parts) ?: $event::class;
    }
}
