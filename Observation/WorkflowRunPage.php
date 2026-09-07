<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * One page of the catalog, and what it takes to ask for the next one.
 *
 * `nextCursor` is opaque: its shape belongs to the backend that issued it, and handing it back to
 * the same catalog is the only thing a caller has the right to do with it. Temporal will return its
 * page token, DBAL a resumption key; the view merely carries it.
 *
 * `null` means "there is nothing after this", and not "I do not know": a page that is exactly full
 * must not promise an empty page, on pain of a "next" that leads nowhere.
 */
final readonly class WorkflowRunPage
{
    /**
     * @param list<WorkflowRunDescription> $runs
     */
    public function __construct(
        public array $runs,
        public ?string $nextCursor = null,
    ) {}
}
