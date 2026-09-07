<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What a backend recorded along with an event, formatted to be read — **once**.
 *
 * The content is the backend's vocabulary and is **not** normalised, by design: deciding, for each
 * backend, which of its facts deserve a common name only makes sense once we have seen what
 * operators look for in there. The accepted trade-off is that a homegrown journal may hold a
 * payload that does not survive rendering, and this is therefore the place where the degradation is
 * decided, for every surface at once.
 *
 * It was not decided in the same place: the Magento block tolerated partial output and fell back to
 * a plain row, the Sylius template called `json_encode` without tolerance, harvested `false` and
 * rendered an **empty disclosure panel** — precisely the screen an operator opens as a last resort,
 * and which opens onto nothing.
 *
 * `null` means "nothing to unfold". In practice this is the case where nothing was recorded:
 * partial output saves all the rest, including a value of a type JSON cannot hold — it comes out as
 * `null` in the payload, and the operator sees both what was recorded and what could not be. The
 * host then leaves a plain row; returning an empty string would take that choice away from it.
 *
 * This is formatting inside the core, like {@see ReadableDuration}, and for the same reason: what
 * several hosts must say the same way is decided in a single place.
 */
final class RecordedDetails
{
    /**
     * @param array<string, mixed> $details
     */
    public static function of(array $details): ?string
    {
        if ([] === $details) {
            return null;
        }

        // `JSON_PARTIAL_OUTPUT_ON_ERROR` saves the reachable case: a byte string that is not
        // valid UTF-8. Without it, the offending byte takes the whole payload with it — the rest,
        // perfectly readable, disappears along with it.
        $rendered = json_encode(
            $details,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // Defensive guard, and measured before being written: with partial output, no input we
        // tried returns `false` — neither an invalid byte, nor a resource, nor six hundred levels
        // of nesting, which all return truncated output. PHP's signature allows it nonetheless,
        // and a row without a disclosure panel is worth more than a diagnostic screen that falls
        // over on the very event you came to look at.
        return false === $rendered ? null : $rendered;
    }
}
