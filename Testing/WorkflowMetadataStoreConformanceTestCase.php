<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see WorkflowMetadataStore} — DUR041.
 *
 * This port has a subtlety worth a suite of its own: `markCompleted()` **does not delete**. The
 * type and the payload stay readable after success, for the profiler and for observability, and it
 * is `hasActiveWorkflowMetadata()` — not `get()` — that says whether a resume must still take
 * place. An adapter that confuses the two makes a finished workflow forever resumable, or makes its
 * type disappear from a dashboard.
 *
 * @see DUR041
 * @see DUR021
 */
abstract class WorkflowMetadataStoreConformanceTestCase extends TestCase
{
    abstract protected function createMetadataStore(): WorkflowMetadataStore;

    public function testWhatWasSavedComesBack(): void
    {
        $store = $this->createMetadataStore();
        $payload = ['order' => ['id' => '0042', 'total' => 12.5, 'shipping' => 30.0], 'flags' => [true, false]];

        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', $payload);
        $stored = $store->get(ExecutionId::fromString('exec-1'));

        self::assertNotNull($stored);
        self::assertSame('App\\OrderWorkflow', $stored['workflowType']);
        self::assertSame($payload, $stored['payload'], 'the payload must cross the storage intact');
    }

    public function testAnUnknownExecutionIsNullAndInactiveRatherThanAnError(): void
    {
        $store = $this->createMetadataStore();

        self::assertNull($store->get(ExecutionId::fromString('exec-nobody')));
        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-nobody')));
    }

    public function testAFreshlySavedExecutionIsActive(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', []);

        self::assertTrue($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));
    }

    /**
     * The core of the port: completing makes it inactive **without** erasing it.
     */
    public function testCompletingLeavesTheRowReadableAndStopsItBeingActive(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', ['input' => 'kept']);

        $store->markCompleted(ExecutionId::fromString('exec-1'));

        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')), 'a finished execution is not resumable any more');

        $stored = $store->get(ExecutionId::fromString('exec-1'));
        self::assertNotNull($stored, 'completing does not delete: the profiler still reads the type');
        self::assertSame('App\\OrderWorkflow', $stored['workflowType']);
        self::assertSame(['input' => 'kept'], $stored['payload']);
    }

    public function testCompletingTwiceIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', []);

        $store->markCompleted(ExecutionId::fromString('exec-1'));
        $store->markCompleted(ExecutionId::fromString('exec-1'));

        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));
        self::assertNotNull($store->get(ExecutionId::fromString('exec-1')));
    }

    public function testCompletingAnUnknownExecutionIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->markCompleted(ExecutionId::fromString('exec-nobody'));

        self::assertNull($store->get(ExecutionId::fromString('exec-nobody')));
    }

    public function testSavingAgainRepublishesTheExecution(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', ['v' => 1]);
        $store->markCompleted(ExecutionId::fromString('exec-1'));

        $store->save(ExecutionId::fromString('exec-1'), 'App\\ContinuedWorkflow', ['v' => 2]);

        $stored = $store->get(ExecutionId::fromString('exec-1'));
        self::assertNotNull($stored);
        self::assertSame('App\\ContinuedWorkflow', $stored['workflowType']);
        self::assertSame(['v' => 2], $stored['payload']);
        self::assertTrue(
            $store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')),
            'writing the metadata again starts from a resumable execution',
        );
    }

    public function testDeletingRemovesTheRowEntirely(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\OrderWorkflow', []);

        $store->delete(ExecutionId::fromString('exec-1'));

        self::assertNull($store->get(ExecutionId::fromString('exec-1')));
        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));
    }

    public function testDeletingAnUnknownExecutionIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->delete(ExecutionId::fromString('exec-nobody'));

        self::assertNull($store->get(ExecutionId::fromString('exec-nobody')));
    }

    public function testExecutionsDoNotLeakIntoEachOther(): void
    {
        $store = $this->createMetadataStore();
        $store->save(ExecutionId::fromString('exec-1'), 'App\\One', ['n' => 1]);
        $store->save(ExecutionId::fromString('exec-2'), 'App\\Two', ['n' => 2]);

        $store->markCompleted(ExecutionId::fromString('exec-1'));
        $store->delete(ExecutionId::fromString('exec-1'));

        $second = $store->get(ExecutionId::fromString('exec-2'));
        self::assertNotNull($second);
        self::assertSame('App\\Two', $second['workflowType']);
        self::assertTrue($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-2')));
    }
}
