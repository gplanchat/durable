<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * The execution backend in use cannot serve a Nexus operation.
 *
 * Nexus has a workflow call an operation that **somebody else** serves — another namespace,
 * another team, another deployment. A backend that keeps its journal locally has nothing to route
 * that call to, and no honest fallback: it can neither run it, nor ignore it without leaving the
 * workflow waiting for a result nobody will produce.
 *
 * Hence this refusal, immediate and named, rather than a command accepted and then lost.
 */
final class NexusUnsupportedByBackendException extends \RuntimeException
{
    /**
     * The refusal **at registration**, and it does not say the same thing as the one at the call.
     *
     * A call on a backend with no route fails at the call: the mistake becomes visible the moment
     * it is made. Serving is the opposite — a handler declared there is not a call that fails, it
     * is a service that **never receives anything**, without a line of log. There is no request to
     * fail later, so the refusal happens here or nowhere.
     */
    public static function forHandlerOn(string $backend): self
    {
        return new self(\sprintf(
            'A Nexus handler cannot be served by the %s backend: it has no route, so a handler registered here never receives anything — no error, no log line, a task queue nobody polls. Use the Temporal backend to serve Nexus operations.',
            $backend,
        ));
    }

    public static function forBackend(string $backend): self
    {
        return new self(\sprintf(
            'The %s backend cannot serve a Nexus operation: Nexus routes a call to an endpoint served elsewhere, and this backend has no such route. Use the Temporal backend to call Nexus operations.',
            $backend,
        ));
    }
}
