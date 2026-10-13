<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\ReopensCompletedWorkflowMetadata;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * Conformance suite for {@see ReopensCompletedWorkflowMetadata}, on top of the metadata store's own.
 */
abstract class ReopensCompletedWorkflowMetadataConformanceTestCase extends WorkflowMetadataStoreConformanceTestCase
{
    abstract protected function createMetadataStore(): WorkflowMetadataStore&ReopensCompletedWorkflowMetadata;

    public function testAReopenReplacesTheTypeAndThePayloadOfACompletedRowAndActivatesIt(): void
    {
        $store = $this->createMetadataStore();
        $id = ExecutionId::fromString('exec-1');
        $store->insertIfAbsent($id, 'App\\One', ['v' => 1]);
        $store->markCompleted($id);

        self::assertTrue($store->reopenIfCompleted($id, 'App\\Two', ['v' => 2]));

        self::assertSame(['workflowType' => 'App\\Two', 'payload' => ['v' => 2], 'completed' => false], $store->get($id));
        self::assertTrue($store->hasActiveWorkflowMetadata($id));
    }

    public function testOfTwoReopensOfOneCompletedRowExactlyOneWins(): void
    {
        $store = $this->createMetadataStore();
        $id = ExecutionId::fromString('exec-1');
        $store->insertIfAbsent($id, 'App\\One', ['v' => 1]);
        $store->markCompleted($id);

        self::assertTrue($store->reopenIfCompleted($id, 'App\\Two', ['v' => 2]));
        self::assertFalse($store->reopenIfCompleted($id, 'App\\Three', ['v' => 3]));

        self::assertSame(['v' => 2], $store->get($id)['payload'] ?? null, 'the loser leaves the winner\'s row as it is');
    }

    public function testAReopenLeavesARunningRowAndAMissingRowAlone(): void
    {
        $store = $this->createMetadataStore();
        $id = ExecutionId::fromString('exec-1');
        $store->insertIfAbsent($id, 'App\\One', ['v' => 1]);

        self::assertFalse($store->reopenIfCompleted($id, 'App\\Two', ['v' => 2]));
        self::assertFalse($store->reopenIfCompleted(ExecutionId::fromString('exec-nobody'), 'App\\Two', []));

        self::assertSame(['v' => 1], $store->get($id)['payload'] ?? null);
        self::assertNull($store->get(ExecutionId::fromString('exec-nobody')));
    }
}
