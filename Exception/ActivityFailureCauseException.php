<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Represents a previous cause serialised from the activity failure history
 * (the original class is kept as text for logs / traces).
 */
final class ActivityFailureCauseException extends \RuntimeException
{
    public function __construct(
        private readonly string $originalClass,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('[%s] %s', $originalClass, $message),
            $code,
            $previous,
        );
    }

    public function originalExceptionClass(): string
    {
        return $this->originalClass;
    }
}
