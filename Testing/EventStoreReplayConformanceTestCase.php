<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * The "replay" tier of DUR041: instead of writing fabricated events, it has them produced by a real
 * workflow — an activity, a timer, two side effects, one with a nested payload — then it compares
 * what the replay reads back from the adapter with what it reads back from the reference.
 *
 * An adapter that drives a workflow inline extends this class and inherits both tiers. An adapter
 * backed by a server must extend {@see EventStoreConformanceTestCase} only, and replay this tier in
 * the integration suite. The cut is in the class declaration, so that a bridge playing only one
 * half of the suite is a visible fact and not an oversight.
 *
 * This docblock claimed that both Temporal stores extended the port tier. **That is false**:
 * neither {@see \Gplanchat\Bridge\Temporal\TemporalJournalEventStore} nor
 * {@see \Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore} extends anything at
 * all, and neither tier runs against them. A bridge playing **no** half was not foreseen by the
 * cut, and that is precisely the oversight it was meant to make visible.
 * The `backend-data-parity` change fills it in; DUR041 carries the real state.
 *
 * The reference, for its part, does not extend this class: a store is not diffed against itself.
 *
 * @see DUR041
 */
abstract class EventStoreReplayConformanceTestCase extends EventStoreConformanceTestCase
{
    public function testAWorkflowRunsIdenticallyAgainstTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        $referenceResult = self::runConformanceWorkflow($reference, 'exec-reference');
        $subjectResult = self::runConformanceWorkflow($subject, 'exec-subject');

        self::assertSame($referenceResult, $subjectResult, 'the workflow must return the same result');
        self::assertSame(
            self::journalShape($reference, 'exec-reference'),
            self::journalShape($subject, 'exec-subject'),
            'both journals must record the same events, in the same order',
        );
    }

    /**
     * `EventStoreHistorySource` reads the stream again on every slot lookup — that is the path the
     * replay actually takes, and it is more demanding than a single read.
     */
    public function testReplaySlotLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(
            $fromReference->findActivitySlotResult(0)['result'],
            $fromSubject->findActivitySlotResult(0)['result'],
        );
        self::assertNull($fromSubject->findActivitySlotResult(1), 'only one activity was scheduled');

        // Side effects carry a `mixed`: that is where a JSON round trip distorts.
        self::assertSame($fromReference->findSideEffectForSlot(0), $fromSubject->findSideEffectForSlot(0));
        self::assertSame($fromReference->findSideEffectForSlot(1), $fromSubject->findSideEffectForSlot(1));

        self::assertNotNull($fromSubject->findScheduledTimerId(0), 'the timer must be read back from the store');
    }

    /**
     * The identity accessors the divergence guard queries (DUR042).
     *
     * They are on the same port as the slot lookups above, but they do not answer the same
     * question: "what happened here" on one side, "what was it" on the other. An adapter may very
     * well return the right result for the right slot and get the identity wrong — and a guard that
     * compares the wrong identity is worth less than no guard, since it would refuse faithful
     * replays.
     *
     * Here rather than in a parity test dedicated to one adapter: every store that extends this
     * class inherits the check, today as tomorrow.
     */
    public function testIdentityLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(
            $fromReference->activityNameForSlot(0),
            $fromSubject->activityNameForSlot(0),
            'the identity of the activity slot must survive the round trip through the store',
        );
        self::assertNotNull($fromSubject->activityNameForSlot(0), 'and must not be lost on the way');

        // A slot the workflow did not reach: null on both sides. That is what distinguishes
        // "nothing to compare" from "divergence", and an adapter answering the empty string would
        // have a growing workflow refused.
        self::assertNull($fromSubject->activityNameForSlot(1));
        self::assertSame($fromReference->activityNameForSlot(1), $fromSubject->activityNameForSlot(1));

        // The conformance workflow starts no child and this backend refuses Nexus (DUR036): both
        // accessors must say so, and say it the same way.
        self::assertNull($fromSubject->childWorkflowTypeForSlot(0));
        self::assertSame($fromReference->childWorkflowTypeForSlot(0), $fromSubject->childWorkflowTypeForSlot(0));
        self::assertNull($fromSubject->nexusOperationSignatureForSlot(0));
        self::assertSame($fromReference->nexusOperationSignatureForSlot(0), $fromSubject->nexusOperationSignatureForSlot(0));
    }

    /**
     * The version an execution recorded must survive the round trip through the store.
     *
     * This is the property the whole of versioning depends on: at replay, the answer comes from the
     * journal. An adapter that lost it would have an in-flight execution resume on the other branch
     * — without signalling anything, since the divergence guard would see code consistent with its
     * new version.
     */
    public function testVersionLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(1, $fromSubject->versionForChangeId('conformance-change'), 'the recorded version comes back unchanged');
        self::assertSame(
            $fromReference->versionForChangeId('conformance-change'),
            $fromSubject->versionForChangeId('conformance-change'),
        );

        // A change point this workflow never declared: null on both sides. That is what
        // distinguishes "not reached yet" from "version 0", and an adapter answering 0 would
        // have an execution take the old branch it was never entitled to.
        self::assertNull($fromSubject->versionForChangeId('jamais-declare'));
        self::assertSame(
            $fromReference->versionForChangeId('jamais-declare'),
            $fromSubject->versionForChangeId('jamais-declare'),
        );
    }

    private static function runConformanceWorkflow(EventStoreInterface $eventStore, string $executionId): mixed
    {
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('durable.conformance.quote', static fn(array $payload): array => [
            'total' => 42.5,
            'currency' => 'EUR',
            'lines' => $payload['lines'] ?? [],
        ]);

        $runner = new InMemoryWorkflowRunner(
            $eventStore,
            new InMemoryActivityTransport(),
            $activityExecutor,
            0,
            new WorkflowRegistry(),
        );

        return $runner->run($executionId, static function (WorkflowEnvironment $wf): array {
            // A change point in the conformance workflow: this is what forces every adapter to
            // round trip the version marker, and not just the reference. A store that lost
            // `VersionMarked` would swing an in-flight execution back onto the other branch —
            // silently.
            $wf->version('conformance-change', ChangePoint::DEFAULT_VERSION, 1);
            $nested = $wf->sideEffect(static fn(): array => ['nested' => ['deep' => true], 'ratio' => 0.1]);
            $quote = $wf->await($wf->activityStub(ConformanceActivities::class)->quote(['a', 'b']));
            $wf->sleep(Duration::seconds(0.001));
            $flag = $wf->sideEffect(static fn(): string => 'after-timer');

            return ['nested' => $nested, 'quote' => $quote, 'flag' => $flag];
        });
    }

    /**
     * @return list<array{string, array<string, mixed>}> event type + payload, in order
     */
    private static function journalShape(EventStoreInterface $store, string $executionId): array
    {
        $shape = [];
        foreach ($store->readStream($executionId) as $event) {
            $shape[] = [$event::class, self::scrub($event->payload())];
        }

        return $shape;
    }

    /**
     * Two distinct executions draw different identifiers and clocks; neutralizing them leaves
     * exactly what the comparison must carry: the shape of the journal.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function scrub(array $payload): array
    {
        $volatile = [
            'timerId', 'activityId', 'sideEffectId', 'childExecutionId', 'executionId',
            'scheduledAt', 'queued_at', 'first_queued_at',
        ];

        foreach ($payload as $key => $value) {
            if (\in_array($key, $volatile, true)) {
                $payload[$key] = '<volatile>';
            } elseif (\is_array($value)) {
                $payload[$key] = self::scrub($value);
            }
        }

        return $payload;
    }
}
