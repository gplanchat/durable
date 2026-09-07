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
        // The transport translates the delay into its own deferral mechanism, then forgets it.
        $at = microtime(true);
        if (null !== $message->retryDelay) {
            $at += $message->retryDelay->toSeconds();
            $message = $message->withoutRetryDelay();
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
     * First ready message, without removing it (tests / distributed orchestration).
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

    public function nextDueAt(): ?float
    {
        $next = null;
        foreach ($this->pending as $row) {
            if (null === $next || $row['at'] < $next) {
                $next = $row['at'];
            }
        }

        return $next;
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
     * Number of messages still queued (all delays taken together).
     */
    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /**
     * Non-destructive read of the messages whose due time has been reached.
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
