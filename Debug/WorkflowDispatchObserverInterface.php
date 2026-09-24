<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Debug;

/**
 * Optional observation of workflow dispatches, before any run: the backend reports each start or
 * resume it hands over, so a debug surface can show dispatches that never cross a message bus.
 */
interface WorkflowDispatchObserverInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param string|null          $transportNames where the dispatch went ("temporal", Messenger transport names)
     */
    public function onWorkflowDispatchRequested(
        string $executionId,
        string $workflowType,
        array $payload,
        bool $isResume,
        ?string $transportNames,
    ): void;
}
