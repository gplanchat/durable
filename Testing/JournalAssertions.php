<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\EventStoreInterface;
use PHPUnit\Framework\Assert;

/**
 * Assertions on what an execution's journal holds, shared by {@see DurableTestCase} and the
 * bundle's `DurableBundleTestTrait`: each reads its own event store and hands it here.
 */
final class JournalAssertions
{
    /**
     * @param string $expectedFailureClass the failure's class name, or '' for any failure
     */
    public static function assertWorkflowFailed(EventStoreInterface $eventStore, string $executionId, string $expectedFailureClass = ''): void
    {
        $failed = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                $failed = $event;
                break;
            }
        }
        Assert::assertNotNull(
            $failed,
            \sprintf('The workflow "%s" did not fail (no WorkflowExecutionFailed in the journal).', $executionId),
        );

        if ('' !== $expectedFailureClass) {
            Assert::assertSame(
                $expectedFailureClass,
                $failed->failureClass(),
                \sprintf('The workflow "%s" failed with another class than the expected one.', $executionId),
            );
        }
    }
}
