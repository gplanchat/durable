<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Why a Nexus operation did not succeed.
 *
 * The four kinds are not a reading convenience: they call for different moves. A caller
 * compensates on {@see self::OperationFailed} — the handler ran and said no. It can retry on
 * {@see self::HandlerError}, where the handler did not run at all and where the server itself
 * says whether resuming makes sense. It does neither on {@see self::Cancellation}, which it most
 * often requested. And {@see self::Timeout} says the bound spoke before the operation did, which
 * is nobody's failure.
 *
 * Flattening the four onto a generic failure would erase exactly what makes the choice possible.
 */
enum NexusOperationFailureKind: string
{
    /** The handler ran and returned a failure. */
    case OperationFailed = 'operation_failed';

    /** The handler could not run; the server carries the retry behaviour. */
    case HandlerError = 'handler_error';

    /** A bound elapsed before the operation completed. */
    case Timeout = 'timeout';

    /** The operation was cancelled. */
    case Cancellation = 'cancellation';
}
