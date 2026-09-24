<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Records one compensation per completed step and runs them in reverse order.
 *
 * A compensation is a closure. When it returns an `Awaitable`, a stub call such as
 * `fn () => $payments->refund($charge)`, the saga awaits it before the next one. Anything else
 * runs inline. The closures live in workflow memory: on replay the workflow rebuilds the saga as
 * it goes, and the journal answers the compensations that already ran.
 *
 * The first compensation that throws stops the run, and its exception replaces the one being
 * compensated. The compensations still pending stay recorded, so calling `compensate()` again
 * resumes with them. Only the first cancellation of an execution spares the awaits of a
 * compensation; a later one interrupts them like any other await.
 *
 * ponytail: sequential, stop on first error; parallel or continue-with-error when a workflow needs it.
 */
final class Saga
{
    /** @var list<\Closure(): mixed> */
    private array $compensations = [];

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @param \Closure(): mixed $compensation
     */
    public function addCompensation(\Closure $compensation): void
    {
        $this->compensations[] = $compensation;
    }

    public function compensate(): void
    {
        while (null !== $compensation = array_pop($this->compensations)) {
            $result = $compensation();

            if ($result instanceof Awaitable) {
                $this->environment->await($result);
            }
        }
    }
}
