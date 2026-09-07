<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * The PHPUnit base class for testing workflows against the in-memory infrastructure.
 *
 * It gives short access to {@see WorkflowTestEnvironment}, and assertion methods that read the
 * event store.
 *
 * Usage:
 * ```php
 * final class MyWorkflowTest extends DurableTestCase
 * {
 *     public function testWorkflowCompletes(): void
 *     {
 *         $spy = ActivitySpy::returns('Hello, World!');
 *         $env = $this->createWorkflowTestEnvironment(['greet' => $spy]);
 *
 *         $result = $env->run(function (WorkflowEnvironment $wf) {
 *             return $wf->await($wf->activity('greet', ['name' => 'World']));
 *         }, $executionId = 'exec-001');
 *
 *         self::assertSame('Hello, World!', $result);
 *         $spy->assertCalledTimes(1);
 *         $this->assertWorkflowCompleted($executionId, 'Hello, World!');
 *         $this->assertActivityExecuted($executionId, 'greet');
 *     }
 * }
 * ```
 */
abstract class DurableTestCase extends TestCase
{
    private ?WorkflowTestEnvironment $currentEnvironment = null;

    /**
     * Creates an in-memory test environment and keeps it for the assertions to read.
     *
     * @param array<string, callable(array<string, mixed>): mixed> $activityHandlers
     */
    protected function createWorkflowTestEnvironment(
        array $activityHandlers = [],
        int $maxActivityRetries = 0,
    ): WorkflowTestEnvironment {
        $env = WorkflowTestEnvironment::inMemory($activityHandlers, $maxActivityRetries);
        $this->currentEnvironment = $env;

        return $env;
    }

    /**
     * Shortcut: creates the environment and returns the runner directly.
     *
     * Suitable for simple tests that do not need the assertions of this class.
     *
     * @param array<string, callable(array<string, mixed>): mixed> $activityHandlers
     */
    protected function createWorkflowRunner(
        array $activityHandlers = [],
        int $maxActivityRetries = 0,
    ): InMemoryWorkflowRunner {
        return $this->createWorkflowTestEnvironment($activityHandlers, $maxActivityRetries)->getRunner();
    }

    /**
     * Checks that the workflow finished normally and that its result is correct.
     */
    protected function assertWorkflowCompleted(string $executionId, mixed $expectedResult): void
    {
        $completed = $this->findEvent($executionId, ExecutionCompleted::class);
        Assert::assertNotNull(
            $completed,
            \sprintf('The workflow "%s" did not finish (no ExecutionCompleted event found).', $executionId),
        );
        Assert::assertEquals(
            $expectedResult,
            $completed->result(),
            \sprintf('The workflow "%s" did not return what was expected.', $executionId),
        );
    }

    /**
     * Checks that the workflow failed, optionally with a specific exception class.
     *
     * @param class-string<\Throwable>|'' $expectedFailureClass
     */
    protected function assertWorkflowFailed(string $executionId, string $expectedFailureClass = ''): void
    {
        $failed = $this->findEvent($executionId, WorkflowExecutionFailed::class);
        Assert::assertNotNull(
            $failed,
            \sprintf('The workflow "%s" did not fail (no WorkflowExecutionFailed event found).', $executionId),
        );

        if ('' !== $expectedFailureClass) {
            Assert::assertSame(
                $expectedFailureClass,
                $failed->failureClass(),
                \sprintf('The workflow "%s" failed with another class than the expected one.', $executionId),
            );
        }
    }

    /**
     * Checks that a named activity was indeed scheduled (and therefore executed) in the workflow.
     */
    protected function assertActivityExecuted(string $executionId, string $activityName): void
    {
        $env = $this->requireCurrentEnvironment();
        $found = false;
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled && $event->activityName() === $activityName) {
                $found = true;
                break;
            }
        }
        Assert::assertTrue(
            $found,
            \sprintf('The activity "%s" was never scheduled in the workflow "%s".', $activityName, $executionId),
        );
    }

    /**
     * Checks that a specific event type is in the event store for this execution.
     *
     * @param class-string $eventClass
     */
    protected function assertEventStoreContains(string $executionId, string $eventClass): void
    {
        $env = $this->requireCurrentEnvironment();
        $found = false;
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof $eventClass) {
                $found = true;
                break;
            }
        }
        Assert::assertTrue(
            $found,
            \sprintf('The event "%s" was not found in the event store for the execution "%s".', $eventClass, $executionId),
        );
    }

    /**
     * Counts how many activities of a given name were scheduled.
     */
    protected function countActivityExecutions(string $executionId, string $activityName): int
    {
        $env = $this->requireCurrentEnvironment();
        $count = 0;
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled && $event->activityName() === $activityName) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Returns the current environment, or throws a LogicException if it was not initialized.
     */
    protected function requireCurrentEnvironment(): WorkflowTestEnvironment
    {
        if (null === $this->currentEnvironment) {
            throw new \LogicException(
                'No WorkflowTestEnvironment was created. Call createWorkflowTestEnvironment() in setUp(), or at the start of the test.',
            );
        }

        return $this->currentEnvironment;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $eventClass
     *
     * @return T|null
     */
    private function findEvent(string $executionId, string $eventClass): ?object
    {
        $env = $this->requireCurrentEnvironment();
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof $eventClass) {
                return $event;
            }
        }

        return null;
    }
}
