<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A run listing was asked for a filter its catalog cannot apply (#558).
 *
 * Thrown rather than answered: an unfiltered page would look filtered, and an empty one would
 * say that nothing matches. A surface asks
 * {@see \Gplanchat\Durable\Port\WorkflowRunCatalogInterface::canFilterRuns()} first, and offers
 * no filter when the answer is no.
 */
final class RunFilterUnavailableException extends \LogicException implements ExceptionInterface {}
