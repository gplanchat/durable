<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

final class InMemoryActivityTransport implements ActivityTransportInterface
{
    /**
     * @var list<array{at: float, message: ActivityMessage}>
     */
    private array $pending = [];

    public function enqueue(ActivityMessage $message): void
    {
        $at = microtime(true);
        $meta = $message->metadata;
        if (isset($meta['retry_delay_seconds']) && (float) $meta['retry_delay_seconds'] > 0) {
            $at += (float) $meta['retry_delay_seconds'];
            unset($meta['retry_delay_seconds']);
            $message = new ActivityMessage(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $message->payload,
                $meta,
            );
        }
        $this->pending[] = ['at' => $at, 'message' => $message];
    }

    public function dequeue(): ?ActivityMessage
    {
        $now = microtime(true);
        $bestIdx = null;
        $bestAt = null;
        foreach ($this->pending as $i => $row) {
            if ($row['at'] <= $now && (null === $bestAt || $row['at'] < $bestAt)) {
                $bestAt = $row['at'];
                $bestIdx = $i;
            }
        }
        if (null === $bestIdx) {
            return null;
        }
        $msg = $this->pending[$bestIdx]['message'];
        array_splice($this->pending, $bestIdx, 1);

        return $msg;
    }

    /**
     * Premier message prêt sans le retirer (tests / orchestration distribuée).
     */
    public function peek(): ?ActivityMessage
    {
        $now = microtime(true);
        $best = null;
        $bestAt = null;
        foreach ($this->pending as $row) {
            if ($row['at'] <= $now && (null === $bestAt || $row['at'] < $bestAt)) {
                $bestAt = $row['at'];
                $best = $row['message'];
            }
        }

        return $best;
    }

    public function isEmpty(): bool
    {
        return null === $this->peek();
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        $removed = false;
        $next = [];
        foreach ($this->pending as $row) {
            if (!$removed && $row['message']->executionId === $executionId && $row['message']->activityId === $activityId) {
                $removed = true;

                continue;
            }
            $next[] = $row;
        }
        $this->pending = $next;

        return $removed;
    }

    /**
     * Nombre de messages encore en file (tous délais confondus).
     */
    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /**
     * Lecture non destructive des messages dont l’heure d’échéance est atteinte.
     *
     * @return list<array{name: string, payload: array<string, mixed>}>
     */
    public function inspectPendingActivities(): array
    {
        $now = microtime(true);
        $snapshot = [];
        foreach ($this->pending as $row) {
            if ($row['at'] <= $now) {
                $snapshot[] = [
                    'name' => $row['message']->activityName,
                    'payload' => $row['message']->payload,
                ];
            }
        }

        return $snapshot;
    }
}
