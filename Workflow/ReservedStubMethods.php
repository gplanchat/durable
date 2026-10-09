<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Exception\ReservedStubMethodName;

/**
 * The names the workflow stub keeps for its own verbs. Hosts call {@see assertFreeFor()} when they
 * register a repository, so the boot fails instead of leaving a method unreachable.
 */
final class ReservedStubMethods
{
    /** Lower case: PHP method names are case-insensitive. */
    private const NAMES = ['start', 'execute', 'result', 'cancel', 'terminate', 'executionid'];

    private function __construct() {}

    /**
     * @param class-string $workflowClass
     *
     * @throws ReservedStubMethodName
     */
    public static function assertFreeFor(string $workflowClass): void
    {
        foreach ((new \ReflectionClass($workflowClass))->getMethods() as $method) {
            if (!\in_array(strtolower($method->getName()), self::NAMES, true)) {
                continue;
            }
            if ([] !== $method->getAttributes(AsSignalMethod::class)
                || [] !== $method->getAttributes(AsQueryMethod::class)
                || [] !== $method->getAttributes(AsUpdateMethod::class)) {
                throw new ReservedStubMethodName($workflowClass, $method->getName());
            }
        }
    }
}
