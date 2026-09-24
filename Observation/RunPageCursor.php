<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Where a page of runs stopped, for a catalogue that pages **by key**: the start date and the
 * execution id of the last run shown. The next page starts strictly after that pair.
 *
 * The encoded form goes out in a URL and comes back with the next request, so it is a wire contract
 * between two requests: base64 of `startedAt`, a NUL byte, then the execution id. Only the first NUL
 * splits, so an execution id may carry one.
 *
 * @see WorkflowRunPage::$nextCursor
 */
final readonly class RunPageCursor
{
    /**
     * @param string $startedAt the start date as the catalogue stored it, compared as stored
     */
    public function __construct(
        public string $startedAt,
        public string $executionId,
    ) {}

    public function encode(): string
    {
        return base64_encode($this->startedAt . "\0" . $this->executionId);
    }

    /**
     * A cursor that does not decode, or whose start is not a date, is the first page, not an error:
     * a hand-edited URL restarts the listing instead of failing it.
     */
    public static function decode(?string $cursor): ?self
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $raw = base64_decode($cursor, true);
        if (false === $raw || !str_contains($raw, "\0")) {
            return null;
        }

        [$startedAt, $executionId] = explode("\0", $raw, 2);

        // The start goes to SQL as a date: one that is not would fail the whole query on PostgreSQL.
        if ('' === $startedAt || false === date_create($startedAt)) {
            return null;
        }

        return new self($startedAt, $executionId);
    }
}
