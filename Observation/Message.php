<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * A text the core words, handed to the host as a key and its parameters (#850).
 *
 * It travels beside the English string the core has always returned, never in its place: a host
 * that has no translation for `key` shows the English string, so a missing entry degrades to
 * today's output. A host with a translator (Symfony `trans`, Laravel `__`, Magento `__()`) looks
 * `key` up and fills the named `params`.
 *
 * Parameters are values, not words: a duration such as `5 min`, a count, a name taken from the
 * application. Anything the core would have to word goes in its own message.
 */
final readonly class Message
{
    /**
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {}
}
