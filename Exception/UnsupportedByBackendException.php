<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution backend in use cannot honour this command (DUR051).
 *
 * Thrown instead of doing nothing: a command accepted and then dropped leaves a workflow waiting
 * on something nobody will produce, with no line of log to say why. The message names the backend,
 * the method, and what to use instead. The Nexus refusal
 * ({@see \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException}) is the same idea, kept in its
 * own class.
 */
final class UnsupportedByBackendException extends \LogicException implements ExceptionInterface
{
    public static function forMethod(string $backend, string $method, string $instead): self
    {
        return new self(\sprintf('The %s backend cannot honour %s(): %s', $backend, $method, $instead));
    }
}
