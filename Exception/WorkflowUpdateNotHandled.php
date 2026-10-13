<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * An update reached a running execution and its pass journalled no outcome: the workflow declares
 * no update method of that name, or was not waiting where it could apply one.
 */
final class WorkflowUpdateNotHandled extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $updateName,
    ) {
        parent::__construct('No update outcome was recorded for the execution.');
    }
}
