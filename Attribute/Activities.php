<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Names the activity contract of an `ActivityStub` parameter of a workflow method.
 *
 * ```php
 * #[AsWorkflowMethod]
 * public function run(string $name, #[Activities(GreetingActivities::class)] ActivityStub $greeting, WorkflowEnvironment $env): string
 * ```
 *
 * The loader reads it once, when the workflow is registered, and hands the method
 * `$env->activityStub(GreetingActivities::class)`. PHP has no runtime generics, so the attribute is
 * what carries the contract; the `@param ActivityStub<GreetingActivities>` docblock is only there
 * for PHPStan.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Activities
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public readonly string $contract,
    ) {}
}
