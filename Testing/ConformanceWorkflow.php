<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The workflow {@see EventStoreReplayConformanceTestCase} runs against every adapter: a change
 * point, an activity, a timer, two side effects (one with a nested payload), a race a timer wins
 * against a failing activity, and a child. A class
 * rather than a closure, so that a worker process running it on a server can register the very
 * same body (#326).
 *
 * @see DUR041
 */
final readonly class ConformanceWorkflow
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
        $executor->register('durable.conformance.reject', static function (): never {
            throw new \RuntimeException('rejected, to lose the race');
        });
    }

    /**
     * @return array{nested: mixed, quote: mixed, flag: mixed, race: string, child: mixed}
     */
    public static function run(WorkflowEnvironment $wf): array
    {
        // A change point in the conformance workflow: this is what forces every adapter to round
        // trip the version marker, and not just the reference. A store that lost `VersionMarked`
        // would swing an in-flight execution back onto the other branch — silently.
        $wf->version('conformance-change', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1);
        $nested = $wf->sideEffect(static fn(): array => ['nested' => ['deep' => true], 'ratio' => 0.1]);
        $quote = $wf->await($wf->activityStub(ConformanceActivities::class)->quote(['a', 'b']));
        $wf->sleep(Duration::seconds(0.001));
        $flag = $wf->sideEffect(static fn(): string => 'after-timer');
        // A race the timer wins: the activity fails at once and waits out a backoff far longer than
        // the timer, so it is cancelled as the loser. Every backend must read that loser back as
        // unsettled, and the await after the race must still go on (#678, #681, #701).
        $winner = $wf->await($wf->any(
            // Not integral on purpose: a JSON store reads 30.0 back as 30, and the journals differ.
            $wf->activityStub(ConformanceActivities::class, new ActivityOptions(
                retryLimit: RetryLimit::ofAttempts(5),
                initialInterval: Duration::seconds(30.5),
                backoffCoefficient: 1.5,
            ))->reject(),
            $wf->timer(Duration::seconds(2)),
        ));
        $child = $wf->await($wf->childWorkflowStub(ConformanceChildWorkflow::class)->run('hello'));

        return ['nested' => $nested, 'quote' => $quote, 'flag' => $flag, 'race' => null === $winner ? 'timer' : 'activity', 'child' => $child];
    }
}
