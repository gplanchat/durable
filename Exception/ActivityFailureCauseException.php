<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Représente une cause précédente sérialisée depuis l'historique d'échec d'activité
 * (la classe d'origine est conservée en texte pour les logs / traces).
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
