<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A start carried options the backend cannot honour. No execution started. An option is honoured
 * or refused, never ignored.
 */
final class WorkflowStartOptionsRefused extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @param list<string> $options every refused option, by the name of its WorkflowStartOptions property
     */
    public function __construct(
        public readonly string $backend,
        public readonly array $options,
    ) {
        parent::__construct('The backend cannot honour some of the start options.');
    }
}
