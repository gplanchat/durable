<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\WorkflowRunDescription;

/**
 * An optional companion of {@see WorkflowRunCatalogInterface}: a run's Nexus operations, with where
 * each one is served and whether it is settled (#671).
 *
 * Every dashboard shows the operations a run waits on: a Nexus operation is the one wait served by
 * someone else. A catalog implements this when its backend can hold them — Temporal; a journal
 * refuses Nexus (DUR036) — and a dashboard asks with `instanceof`, so implementing the catalog port
 * alone stays enough.
 */
interface NexusOperationCatalogInterface
{
    /**
     * @return list<NexusOperationSummary> in scheduling order
     */
    public function readNexusOperations(WorkflowRunDescription $run): array;
}
