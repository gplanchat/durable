<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Le runner in-memory ne peut pas mener l'exécution à terme.
 *
 * Signalée plutôt que bouclée à vide : un harnais de test doit échouer, pas geler.
 */
final class WorkflowStuckException extends \RuntimeException
{
    private function __construct(
        public readonly string $executionId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * L'exécution attend quelque chose que ce runner ne produit pas.
     */
    public static function noProgress(string $executionId): self
    {
        return new self($executionId, \sprintf(
            'Workflow %s is suspended on something the in-memory runner cannot settle '
            .'(undelivered signal / update, or a timer that is not due). '
            .'Append the awaited event to the store before running, or use the distributed backend.',
            $executionId,
        ));
    }

    /**
     * L'exécution avance encore mais dépasse le budget : typiquement une activité qui échoue et
     * que la politique par défaut retente indéfiniment.
     */
    public static function budgetExhausted(string $executionId, float $budgetSeconds): self
    {
        return new self($executionId, \sprintf(
            'Workflow %s did not finish within %.1fs. Activities retry indefinitely by default '
            .'(RetryLimit::unlimited(), Temporal semantics): pass RetryLimit::ofAttempts(n) or '
            .'RetryLimit::once(), declare the exception non-retryable, or raise the runner budget.',
            $executionId,
            $budgetSeconds,
        ));
    }
}
