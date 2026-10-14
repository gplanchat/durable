<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * The repository {@see WorkflowClientPortConformanceTestCase} builds over a backend's catalogue.
 *
 * @extends WorkflowRepository<ConformanceClientWorkflow>
 */
#[AsWorkflowRepository(ConformanceClientWorkflow::class)]
final class ConformanceClientWorkflowRepository extends WorkflowRepository {}
