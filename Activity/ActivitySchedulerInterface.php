<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Awaitable\Awaitable;

/**
 * What an {@see ActivityStub} needs, and nothing more: enough to schedule an activity.
 *
 * The stub used to receive the whole environment when it only uses one verb of it. Handing it
 * over left it the means to sleep, to run, to restart itself — and above all it forced the
 * primitive `activity(string $name, array $payload)` to stay public, the one the library no
 * longer teaches: a typo there produces an activity that is never scheduled, instead of a type
 * error.
 *
 * This port is not carried by {@see \Gplanchat\Durable\WorkflowEnvironment}: implementing it
 * there would amount to making the verb public under another name. It is carried by an adapter
 * the environment builds and never hands out.
 */
interface ActivitySchedulerInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function scheduleActivity(string $activityName, array $payload, ?ActivityOptions $options): Awaitable;
}
