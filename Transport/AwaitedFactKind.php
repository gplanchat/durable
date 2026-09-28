<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * The kinds of fact a resume sent first can announce (DUR052). Updates have none: they travel
 * inside the resume, and nothing is appended before it.
 */
enum AwaitedFactKind: string
{
    case Activity = 'activity';
    case Child = 'child';
    case Signal = 'signal';
    case Timer = 'timer';
}
