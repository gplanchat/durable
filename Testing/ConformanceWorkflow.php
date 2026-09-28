<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The workflow {@see EventStoreReplayConformanceTestCase} runs against every adapter: a change
 * point, an activity, a timer, two side effects (one with a nested payload) and a child. A class
 * rather than a closure, so that a worker process running it on a server can register the very
 * same body (#326).
 *
 * @see DUR041
 */
final class ConformanceWorkflow
{
    public const TYPE = 'durable.conformance';

    private function __construct() {}

    public static function registerActivity(RegistryActivityExecutor $executor): void
    {
        $executor->register('durable.conformance.quote', static fn(array $payload): array => [
            'total' => 42.5,
            'currency' => 'EUR',
            'lines' => $payload['lines'] ?? [],
        ]);
    }

    /**
     * @return array{nested: mixed, quote: mixed, flag: mixed, child: mixed}
     */
    public static function run(WorkflowEnvironment $wf): array
    {
        // A change point in the conformance workflow: this is what forces every adapter to round
        // trip the version marker, and not just the reference. A store that lost `VersionMarked`
        // would swing an in-flight execution back onto the other branch — silently.
        $wf->version('conformance-change', ChangePoint::DEFAULT_VERSION, 1);
        $nested = $wf->sideEffect(static fn(): array => ['nested' => ['deep' => true], 'ratio' => 0.1]);
        $quote = $wf->await($wf->activityStub(ConformanceActivities::class)->quote(['a', 'b']));
        $wf->sleep(Duration::seconds(0.001));
        $flag = $wf->sideEffect(static fn(): string => 'after-timer');
        $child = $wf->await($wf->childWorkflowStub(ConformanceChildWorkflow::class)->run('hello'));

        return ['nested' => $nested, 'quote' => $quote, 'flag' => $flag, 'child' => $child];
    }
}
