<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Suite de conformité de {@see WorkflowMetadataStore} — DUR041.
 *
 * Ce port a une subtilité qui vaut une suite à elle seule : `markCompleted()` **ne supprime pas**.
 * Le type et le payload restent lisibles après le succès pour le profiler et l'observabilité, et
 * c'est `hasActiveWorkflowMetadata()` — pas `get()` — qui dit si une reprise doit encore avoir
 * lieu. Un adaptateur qui confond les deux rend un workflow terminé éternellement reprenable, ou
 * fait disparaître son type d'un tableau de bord.
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
        $payload = ['order' => ['id' => '0042', 'total' => 12.5], 'flags' => [true, false]];

        $store->save('exec-1', 'App\\OrderWorkflow', $payload);
        $stored = $store->get('exec-1');

        self::assertNotNull($stored);
        self::assertSame('App\\OrderWorkflow', $stored['workflowType']);
        self::assertSame($payload, $stored['payload'], 'le payload doit traverser le stockage intact');
    }

    public function testAnUnknownExecutionIsNullAndInactiveRatherThanAnError(): void
    {
        $store = $this->createMetadataStore();

        self::assertNull($store->get('exec-nobody'));
        self::assertFalse($store->hasActiveWorkflowMetadata('exec-nobody'));
    }

    public function testAFreshlySavedExecutionIsActive(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\OrderWorkflow', []);

        self::assertTrue($store->hasActiveWorkflowMetadata('exec-1'));
    }

    /**
     * Le cœur du port : terminer rend inactif **sans** effacer.
     */
    public function testCompletingLeavesTheRowReadableAndStopsItBeingActive(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\OrderWorkflow', ['input' => 'kept']);

        $store->markCompleted('exec-1');

        self::assertFalse($store->hasActiveWorkflowMetadata('exec-1'), 'une exécution terminée ne se reprend plus');

        $stored = $store->get('exec-1');
        self::assertNotNull($stored, 'terminer ne supprime pas : le profiler lit encore le type');
        self::assertSame('App\\OrderWorkflow', $stored['workflowType']);
        self::assertSame(['input' => 'kept'], $stored['payload']);
    }

    public function testCompletingTwiceIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\OrderWorkflow', []);

        $store->markCompleted('exec-1');
        $store->markCompleted('exec-1');

        self::assertFalse($store->hasActiveWorkflowMetadata('exec-1'));
        self::assertNotNull($store->get('exec-1'));
    }

    public function testCompletingAnUnknownExecutionIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->markCompleted('exec-nobody');

        self::assertNull($store->get('exec-nobody'));
    }

    public function testSavingAgainRepublishesTheExecution(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\OrderWorkflow', ['v' => 1]);
        $store->markCompleted('exec-1');

        $store->save('exec-1', 'App\\ContinuedWorkflow', ['v' => 2]);

        $stored = $store->get('exec-1');
        self::assertNotNull($stored);
        self::assertSame('App\\ContinuedWorkflow', $stored['workflowType']);
        self::assertSame(['v' => 2], $stored['payload']);
        self::assertTrue(
            $store->hasActiveWorkflowMetadata('exec-1'),
            'réécrire les métadonnées repart d\'une exécution reprenable',
        );
    }

    public function testDeletingRemovesTheRowEntirely(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\OrderWorkflow', []);

        $store->delete('exec-1');

        self::assertNull($store->get('exec-1'));
        self::assertFalse($store->hasActiveWorkflowMetadata('exec-1'));
    }

    public function testDeletingAnUnknownExecutionIsNotAnError(): void
    {
        $store = $this->createMetadataStore();
        $store->delete('exec-nobody');

        self::assertNull($store->get('exec-nobody'));
    }

    public function testExecutionsDoNotLeakIntoEachOther(): void
    {
        $store = $this->createMetadataStore();
        $store->save('exec-1', 'App\\One', ['n' => 1]);
        $store->save('exec-2', 'App\\Two', ['n' => 2]);

        $store->markCompleted('exec-1');
        $store->delete('exec-1');

        $second = $store->get('exec-2');
        self::assertNotNull($second);
        self::assertSame('App\\Two', $second['workflowType']);
        self::assertTrue($store->hasActiveWorkflowMetadata('exec-2'));
    }
}
