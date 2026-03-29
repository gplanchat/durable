<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Raisons standard d'annulation d'une activité encore en file.
 */
final class ActivityCancellationReason
{
    public const RACE_SUPERSEDED = 'race_superseded';

    private function __construct()
    {
    }
}
