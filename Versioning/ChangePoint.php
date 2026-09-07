<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Versioning;

/**
 * The constants of a declared change point.
 *
 * The marker name and the `details` keys are not choices: they were read off the history that a
 * versioned Go SDK workflow produces, then re-emitted from this bridge and accepted by the server
 * (tasks 1.1–1.2). Changing them would break the readability of a Durable execution in the Temporal
 * UI, and interoperability with the other SDKs.
 */
final class ChangePoint
{
    /**
     * What an execution that went past this point **before** it was declared receives.
     *
     * `-1`, as in the official SDKs: the original behaviour has no number because at the time there
     * was nothing to number.
     */
    public const DEFAULT_VERSION = -1;

    /** The name the server and the Temporal UI recognize. */
    public const MARKER_NAME = 'Version';

    /** The two `details` keys, read off the Go SDK's history. */
    public const DETAIL_CHANGE_ID = 'change-id';
    public const DETAIL_VERSION = 'version';

    /** The standard search attribute that makes "who is still on version N" queryable. */
    public const SEARCH_ATTRIBUTE = 'TemporalChangeVersion';

    private function __construct() {}

    /** The value the Go SDK puts in `TemporalChangeVersion`: `<change-id>-<version>`. */
    public static function searchAttributeValue(string $changeId, int $version): string
    {
        return \sprintf('%s-%d', $changeId, $version);
    }
}
