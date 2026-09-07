<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * An execution has met a change point, and the answer it received is now its own — forever.
 *
 * It is this record that tells versioning apart from guesswork: on replay, the answer comes from
 * here and not from the deployed code, so an execution in flight keeps its behaviour whatever
 * gets deployed next.
 */
final readonly class VersionMarked implements Event
{
    public function __construct(
        private string $executionId,
        private string $changeId,
        private int $version,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function changeId(): string
    {
        return $this->changeId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function payload(): array
    {
        return [
            'changeId' => $this->changeId,
            'version' => $this->version,
        ];
    }
}
