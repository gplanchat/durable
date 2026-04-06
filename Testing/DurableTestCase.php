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
 * Classe de base PHPUnit pour tester des workflows avec l'infrastructure in-memory.
 *
 * Fournit un accès simplifié à {@see WorkflowTestEnvironment} et des méthodes
 * d'assertion spécialisées sur l'event store.
 *
 * Usage :
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
     * Crée un environnement de test in-memory et le mémorise pour les assertions.
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
     * Raccourci : crée l'environnement et retourne directement le runner.
     *
     * Convient pour les tests simples qui n'ont pas besoin des assertions de cette classe.
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
     * Vérifie que le workflow s'est terminé normalement et que son résultat est correct.
     */
    protected function assertWorkflowCompleted(string $executionId, mixed $expectedResult): void
    {
        $completed = $this->findEvent($executionId, ExecutionCompleted::class);
        Assert::assertNotNull(
            $completed,
            \sprintf('Le workflow "%s" ne s\'est pas terminé (aucun événement ExecutionCompleted trouvé).', $executionId),
        );
        Assert::assertEquals(
            $expectedResult,
            $completed->result(),
            \sprintf('Le résultat du workflow "%s" ne correspond pas à l\'attendu.', $executionId),
        );
    }

    /**
     * Vérifie que le workflow a échoué, optionnellement avec une classe d'exception précise.
     *
     * @param class-string<\Throwable>|'' $expectedFailureClass
     */
    protected function assertWorkflowFailed(string $executionId, string $expectedFailureClass = ''): void
    {
        $failed = $this->findEvent($executionId, WorkflowExecutionFailed::class);
        Assert::assertNotNull(
            $failed,
            \sprintf('Le workflow "%s" n\'a pas échoué (aucun événement WorkflowExecutionFailed trouvé).', $executionId),
        );

        if ('' !== $expectedFailureClass) {
            Assert::assertSame(
                $expectedFailureClass,
                $failed->failureClass(),
                \sprintf('La classe d\'échec du workflow "%s" ne correspond pas.', $executionId),
            );
        }
    }

    /**
     * Vérifie qu'une activité nommée a bien été planifiée (et donc exécutée) dans le workflow.
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
            \sprintf('L\'activité "%s" n\'a pas été planifiée dans le workflow "%s".', $activityName, $executionId),
        );
    }

    /**
     * Vérifie qu'un type d'événement précis se trouve dans l'event store pour cette exécution.
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
            \sprintf('L\'événement "%s" n\'a pas été trouvé dans l\'event store pour l\'exécution "%s".', $eventClass, $executionId),
        );
    }

    /**
     * Compte combien d'activités d'un nom donné ont été planifiées.
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
     * Retourne l'environnement courant ou lève une LogicException s'il n'a pas été initialisé.
     */
    protected function requireCurrentEnvironment(): WorkflowTestEnvironment
    {
        if (null === $this->currentEnvironment) {
            throw new \LogicException(
                'Aucun WorkflowTestEnvironment n\'a été créé. Appelez createWorkflowTestEnvironment() dans setUp() ou au début du test.',
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
