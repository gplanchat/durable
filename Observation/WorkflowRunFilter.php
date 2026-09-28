<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What narrows a run listing besides its status (#558, #557).
 *
 * Both compare exactly what the application gave, case included, on every catalog:
 * `workflowName` is the whole name, `executionIdPrefix` the start of the execution id, with `%`,
 * `_` and every other character taken literally. An empty string is no filter, as a blank form
 * field is.
 */
final readonly class WorkflowRunFilter
{
    public ?string $workflowName;
    public ?string $executionIdPrefix;

    public function __construct(?string $workflowName = null, ?string $executionIdPrefix = null)
    {
        $this->workflowName = '' === $workflowName ? null : $workflowName;
        $this->executionIdPrefix = '' === $executionIdPrefix ? null : $executionIdPrefix;
    }

    /** In characters, for a SQL `SUBSTR`: a `LIKE` has wildcards and folds case. No ext-mbstring. */
    public function executionIdPrefixLength(): int
    {
        $prefix = $this->executionIdPrefix ?? '';
        $characters = preg_match_all('/./su', $prefix);

        return false === $characters ? \strlen($prefix) : $characters;
    }

    public function isEmpty(): bool
    {
        return null === $this->workflowName && null === $this->executionIdPrefix;
    }
}
