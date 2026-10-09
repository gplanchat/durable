<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution exists, and belongs to another workflow class than the repository's.
 */
final class WorkflowTypeMismatch extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $expectedType,
        public readonly string $actualType,
    ) {
        parent::__construct('The execution belongs to another workflow type than this repository serves.');
    }
}
