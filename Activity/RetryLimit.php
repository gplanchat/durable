<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Jusqu'où l'on est prêt à réessayer une activité.
 *
 * Remplace l'entier `maxAttempts` où 0 signifiait « illimité » : une valeur magique que chaque
 * site d'appel devait retraduire, et qui laissait l'arithmétique de comparaison se disperser
 * dans le processeur d'activité et le runtime. La question du domaine — « cette tentative
 * est-elle encore permise ? » — se pose désormais à l'objet lui-même
 * ({@see allowsAttempt()}).
 *
 * Sur le fil, la représentation reste l'entier Temporal (`maximum_attempts`, 0 = illimité) :
 * c'est le langage du serveur, et il voyage dans l'historique des exécutions en cours.
 */
final readonly class RetryLimit
{
    /** Valeur de fil pour « illimité », alignée sur `RetryPolicy.maximum_attempts`. */
    private const WIRE_UNLIMITED = 0;

    private function __construct(
        /** Nombre total de tentatives permises, ou null quand rien ne les borne. */
        private ?int $maxAttempts,
    ) {}

    /**
     * Réessayer sans borne de nombre.
     *
     * Ce qui arrête alors les tentatives : une exception déclarée non-retryable, un timeout
     * (schedule-to-start, schedule-to-close, start-to-close), ou l'annulation de l'exécution.
     * C'est le comportement d'une RetryPolicy Temporal sans `maximum_attempts`, et celui d'une
     * activité planifiée sans options.
     */
    public static function unlimited(): self
    {
        return new self(null);
    }

    /**
     * Borner à un nombre **total** de tentatives, première comprise.
     */
    public static function ofAttempts(int $attempts): self
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A bounded retry limit needs at least one attempt, %d given. Use RetryLimit::unlimited() for no bound.',
                $attempts,
            ));
        }

        return new self($attempts);
    }

    /**
     * Borner à un nombre de **retentatives** : la tentative initiale s'y ajoute.
     *
     * Vocabulaire du plafond bundle (`max_activity_retries`), qui compte les reprises et non les
     * tentatives. Zéro retentative n'y a jamais voulu dire « une seule tentative » mais
     * « aucun plafond » — d'où {@see unlimited()}.
     */
    public static function ofRetries(int $retries): self
    {
        return $retries > 0 ? self::ofAttempts($retries + 1) : self::unlimited();
    }

    /**
     * Une seule tentative : tout échec est définitif.
     */
    public static function once(): self
    {
        return new self(1);
    }

    public static function fromWireValue(int $maximumAttempts): self
    {
        return self::WIRE_UNLIMITED === $maximumAttempts || $maximumAttempts < 0
            ? self::unlimited()
            : self::ofAttempts($maximumAttempts);
    }

    public function toWireValue(): int
    {
        return $this->maxAttempts ?? self::WIRE_UNLIMITED;
    }

    public function isUnlimited(): bool
    {
        return null === $this->maxAttempts;
    }

    /**
     * Nombre total de tentatives permises, ou null si rien ne les borne.
     */
    public function maxAttempts(): ?int
    {
        return $this->maxAttempts;
    }

    /**
     * La tentative n° {@code $attempt} (1-based) est-elle encore permise ?
     */
    public function allowsAttempt(int $attempt): bool
    {
        return null === $this->maxAttempts || $attempt <= $this->maxAttempts;
    }

    /**
     * La borne la plus stricte des deux : une activité qui fixe la sienne n'échappe pas au
     * plafond de l'application, et réciproquement.
     */
    public function narrowedTo(self $other): self
    {
        if ($this->isUnlimited()) {
            return $other;
        }
        if ($other->isUnlimited()) {
            return $this;
        }

        return new self(min($this->maxAttempts, $other->maxAttempts));
    }
}
