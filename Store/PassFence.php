<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * The epoch a pass claimed for its execution (DUR053). Only the newest claim may append.
 */
final readonly class PassFence
{
    public function __construct(
        public string $executionId,
        /** 0 is no fence: {@see none()}, handed out by a store that cannot fence. */
        public int $epoch,
    ) {}

    /** A fence that fences nothing: a decorator over a store without the capability hands it out. */
    public static function none(string $executionId): self
    {
        return new self($executionId, 0);
    }

    public function fences(): bool
    {
        return $this->epoch > 0;
    }
}
