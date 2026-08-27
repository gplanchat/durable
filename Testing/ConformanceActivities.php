<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\ActivityMethod;

/**
 * Le contrat d'activité que {@see EventStoreReplayConformanceTestCase} planifie. Une seule méthode,
 * dont le résultat n'est pas scalaire : c'est le retour d'activité qui traverse le journal.
 *
 * @see DUR041
 */
interface ConformanceActivities
{
    /**
     * @param list<string> $lines
     */
    #[ActivityMethod('durable.conformance.quote')]
    public function quote(array $lines): mixed;
}
