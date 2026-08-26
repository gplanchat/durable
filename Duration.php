<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Une longueur de temps.
 *
 * Vit à la racine du domaine : activités, workflows et minuteurs bornent tous le temps, et ils
 * doivent en parler de la même façon.
 *
 * Remplace les `?float …Seconds` : l'unité vivait dans le nom du champ, jamais dans le type, et
 * chaque lecteur devait redire `null !== $x && $x > 0` avant de s'en servir. Les comparaisons du
 * domaine — « ce délai est-il écoulé ? », « lequel est le plus court ? » — se posent désormais à
 * l'objet.
 *
 * Sur le fil, la représentation reste un nombre de secondes : c'est ce que porte déjà
 * l'historique des exécutions en cours.
 */
final readonly class Duration
{
    private function __construct(
        private float $seconds,
    ) {}

    public static function seconds(float $seconds): self
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A duration cannot be negative, %.3fs given.', $seconds));
        }

        return new self($seconds);
    }

    public static function milliseconds(float $milliseconds): self
    {
        return self::seconds($milliseconds / 1_000.0);
    }

    public static function minutes(float $minutes): self
    {
        return self::seconds($minutes * 60.0);
    }

    public static function hours(float $hours): self
    {
        return self::seconds($hours * 3_600.0);
    }

    /**
     * Depuis un intervalle natif.
     *
     * Couvre Carbon sans en dépendre : `CarbonInterval` étend `DateInterval`. Les unités
     * calendaires (années, mois) n'ont pas de longueur fixe ; elles sont résolues contre une
     * ancre UTC fixe, donc approximatives — préférer jours/heures/minutes pour une borne
     * temporelle.
     */
    public static function of(\DateInterval $interval): self
    {
        $anchor = new \DateTimeImmutable('@0');
        $shifted = $anchor->add($interval);

        return self::seconds(
            ((float) $shifted->format('U.u')) - ((float) $anchor->format('U.u')),
        );
    }

    /**
     * D'ici (ou d'un instant donné) jusqu'à une échéance.
     *
     * Un `DateTimeInterface` est un **instant**, pas une longueur : `Carbon` inclus, il ne
     * devient une durée que rapporté à un autre instant. C'est pourquoi la méthode ne s'appelle
     * pas `of()`.
     */
    public static function until(\DateTimeInterface $deadline, ?\DateTimeInterface $from = null): self
    {
        $from ??= new \DateTimeImmutable();

        return self::seconds(((float) $deadline->format('U.u')) - ((float) $from->format('U.u')));
    }

    /**
     * Coercition de frontière : accepte ce que l'appelant a sous la main.
     *
     * Un nombre est lu en secondes, un {@see \DateInterval} (donc un `CarbonInterval`) comme une
     * longueur, un {@see \DateTimeInterface} (donc un `Carbon`) comme une échéance à partir de
     * maintenant.
     */
    public static function from(self|\DateInterval|\DateTimeInterface|int|float $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof \DateInterval => self::of($value),
            $value instanceof \DateTimeInterface => self::until($value),
            default => self::seconds((float) $value),
        };
    }

    /**
     * Aucune attente. Distinct de « pas de borne », qui se dit `null`.
     */
    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * Décodage de fil : une valeur absente, nulle ou négative signifie « pas de borne ».
     *
     * C'est la convention Temporal, où un timeout à 0 vaut « non renseigné ».
     */
    public static function fromWireValue(mixed $seconds): ?self
    {
        if (!is_numeric($seconds)) {
            return null;
        }
        $value = (float) $seconds;

        return $value > 0.0 ? new self($value) : null;
    }

    public function toSeconds(): float
    {
        return $this->seconds;
    }

    public function isZero(): bool
    {
        return 0.0 === $this->seconds;
    }

    public function isLongerThan(self $other): bool
    {
        return $this->seconds > $other->seconds;
    }

    public function shortest(self $other): self
    {
        return $this->seconds <= $other->seconds ? $this : $other;
    }

    public function multipliedBy(float $factor): self
    {
        return self::seconds($this->seconds * $factor);
    }

    /**
     * Cette durée s'est-elle écoulée depuis l'instant donné ?
     *
     * Les deux instants sont des timestamps flottants ({@see microtime()}), comme partout où le
     * moteur mesure l'attente d'une activité.
     */
    public function hasElapsedSince(float $startedAt, float $now): bool
    {
        return ($now - $startedAt) > $this->seconds;
    }

    /**
     * Retour vers un intervalle natif, à la microseconde.
     */
    public function toDateInterval(): \DateInterval
    {
        $anchor = new \DateTimeImmutable('@0');

        return $anchor->diff($anchor->modify(\sprintf('+%d microseconds', (int) round($this->seconds * 1_000_000.0))));
    }

    public function __toString(): string
    {
        return \sprintf('%.3fs', $this->seconds);
    }
}
