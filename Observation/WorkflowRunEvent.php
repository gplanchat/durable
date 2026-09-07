<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * One event from an execution's history, in the component's vocabulary.
 *
 * `label` is what a human reads — the name of the activity, of the signal, of the update — and not
 * a technical identifier. When the backend really cannot name a thing, the identifier is an
 * accepted fallback, not a design flaw: an id is worth more than a row without a name.
 *
 * `sequence` is the recording order, not an identifier: it serves to sort, and two backends have no
 * reason to number things the same way.
 *
 * `details` is what a single row cannot hold: an activity's input, its result, the class and the
 * message of a failure, the delays of a scheduling. A frieze without it answers "what" and never
 * "with what" — and that is the second question an operator asks, right after the first. Empty is
 * a valid answer: not every event has something to fill it with.
 *
 * ⚠ The content is **the backend's vocabulary**, not ours: the keys of a homegrown journal are not
 * those of the Temporal history. Normalising it would mean deciding, for each backend, what
 * deserves a common name — work that only makes sense once we have seen what operators look for in
 * there. In the meantime, showing the raw shape does not lie.
 *
 * `actionKey` is **the action** the event is part of: an activity scheduled, started and then
 * finished is one action and three events. Without it, a frieze can only file by kind —
 * "activities", "signals" — and the operator who wants to know how long *this* activity lasted has
 * to piece three rows back together by eye. The key has no meaning outside its execution and needs
 * none: it serves to group, not to designate.
 *
 * `null` means "this event is its own action all by itself" — the start of an execution, a signal
 * received. It is an answer, not the absence of one.
 *
 * `started` says that **the work begins here**: a worker has picked up the task, the child
 * execution has started, the operation has begun. What precedes such an event within its action is
 * therefore not work but a **wait to be picked up** — the queue. Two bars of the same length do not
 * tell the same story depending on whether the time was spent working or waiting for someone to be
 * willing to begin, and that is an operator's first question when facing a slow execution: is it my
 * code, or did nobody answer?
 *
 * `failed` marks **the event** that went wrong, not the action nor the execution: an activity
 * resumed after two failures carries some red and ends well, and that is exactly what an operator
 * must be able to read at a glance. A cancellation and an interruption are not failures: they are
 * outcomes, decided by someone, and painting them as breakdowns would send people looking for a
 * breakdown where there is only a decision.
 *
 * @phpstan-type Details array<string, mixed>
 */
final readonly class WorkflowRunEvent
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $recordedAt,
        public WorkflowRunEventKind $kind,
        public string $label,
        public array $details = [],
        public ?string $actionKey = null,
        public bool $started = false,
        public bool $failed = false,
    ) {}
}
