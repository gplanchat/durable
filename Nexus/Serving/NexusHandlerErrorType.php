<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

/**
 * The error types nexus-rpc defines, and what the server does with them.
 *
 * Nothing is invented here: the table comes from the **nexus-rpc** SDK, shared by every language,
 * and arbitration 1b.3 took it as it stands. The dividing line is *whose fault it is* — a malformed
 * request or a missing right will not get any better on a retry; an overload or an upstream delay,
 * perhaps.
 *
 * What getting it wrong costs is measured (probe 1.7): a retryable error comes back every ~9
 * seconds, on the `request-timeout` clock, until the operation's budget is exhausted. A handler
 * that refuses an invalid input by saying "try again" refuses the same input for a minute and a
 * half.
 */
enum NexusHandlerErrorType: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Unauthorized = 'UNAUTHORIZED';
    case NotFound = 'NOT_FOUND';
    case NotImplemented = 'NOT_IMPLEMENTED';
    case Conflict = 'CONFLICT';
    case ResourceExhausted = 'RESOURCE_EXHAUSTED';
    case Internal = 'INTERNAL';
    case Unavailable = 'UNAVAILABLE';
    case UpstreamTimeout = 'UPSTREAM_TIMEOUT';
    case RequestTimeout = 'REQUEST_TIMEOUT';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::BadRequest, self::Unauthenticated, self::Unauthorized,
            self::NotFound, self::NotImplemented, self::Conflict => false,
            self::ResourceExhausted, self::Internal, self::Unavailable,
            self::UpstreamTimeout, self::RequestTimeout => true,
        };
    }
}
