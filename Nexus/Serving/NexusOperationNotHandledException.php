<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;

/**
 * Nobody serves this operation.
 *
 * The error is **terminal**, and that is the point: `NOT_IMPLEMENTED` is on the non-retryable side
 * of the 1b.3 table. Calling it retryable would ask for the same operation again every ~9 seconds
 * for the whole budget of the operation (probe 1.7), for an answer that will not change — the
 * handler will not appear between two attempts.
 */
final class NexusOperationNotHandledException extends \RuntimeException
{
    public function __construct(
        public readonly NexusService $service,
        public readonly NexusOperationName $operation,
    ) {
        parent::__construct(\sprintf(
            'No Nexus handler is registered for operation "%s" of service "%s".',
            $operation->name(),
            $service->name(),
        ));
    }

    public function type(): NexusHandlerErrorType
    {
        return NexusHandlerErrorType::NotImplemented;
    }
}
