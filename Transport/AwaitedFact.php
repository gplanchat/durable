<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * What a resume sent before its fact announces, and waits for (DUR050, DUR052).
 *
 * A resume that finds its fact missing from the journal concludes nothing: the transport's retry
 * is the wait. Serialized with the message, hence plain strings and an enum.
 */
final readonly class AwaitedFact
{
    /**
     * @param non-empty-list<string> $ids
     */
    private function __construct(
        public AwaitedFactKind $kind,
        public array $ids,
    ) {}

    public static function activity(string $activityId): self
    {
        return new self(AwaitedFactKind::Activity, [$activityId]);
    }

    public static function child(string $childExecutionId): self
    {
        return new self(AwaitedFactKind::Child, [$childExecutionId]);
    }

    /**
     * @param non-empty-list<string> $timerIds
     */
    public static function timers(array $timerIds): self
    {
        return new self(AwaitedFactKind::Timer, $timerIds);
    }

    public function isJournalledIn(EventStoreInterface $journal, string $executionId): bool
    {
        if (AwaitedFactKind::Activity === $this->kind) {
            return ActivityEventJournal::hasTerminalOutcomeForActivity($journal, $executionId, $this->ids[0]);
        }

        $missing = array_fill_keys($this->ids, true);
        foreach ($journal->readStream($executionId) as $event) {
            $id = match (true) {
                AwaitedFactKind::Child === $this->kind && ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) => $event->childExecutionId(),
                // A named timer may be cancelled before it fires; that settles it too.
                AwaitedFactKind::Timer === $this->kind && ($event instanceof TimerCompleted || $event instanceof TimerCancelled) => $event->timerId(),
                default => null,
            };
            unset($missing[$id ?? '']);
        }

        return [] === $missing;
    }

    public function describe(): string
    {
        return $this->kind->value . ' ' . implode(', ', $this->ids);
    }
}
