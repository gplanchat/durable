<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Exception\ExceptionInterface;

/**
 * What {@see ConformanceUpdatableClientWorkflow}'s `explode` update throws.
 */
final class ConformanceUpdateRefused extends \RuntimeException implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct('The conformance update was refused.');
    }
}
