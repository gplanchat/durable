<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Les bornes temporelles d'une activité, prises ensemble.
 *
 * Elles ne se lisent pas isolément — chacune borne un segment différent de la vie d'une
 * activité, et c'est leur composition qui a un sens :
 *
 *     planifiée ──schedule-to-start──▶ démarrée ──start-to-close──▶ terminée
 *     └────────────────── schedule-to-close ─────────────────────────┘
 *                              heartbeat : silence maximal pendant l'exécution
 *
 * Les quatre champs étaient quatre `?float` indépendants dans {@see ActivityOptions}, que chaque
 * lecteur retestait un par un. Les regrouper permet aussi de nommer la règle du serveur :
 * une activité doit avoir une borne de fermeture ({@see executionBoundOr()}).
 */
final readonly class ActivityTimeouts
{
    public function __construct(
        /** De la planification au démarrage effectif : combien de temps accepter d'attendre en file. */
        public ?Duration $scheduleToStart = null,
        /** Du démarrage à la fin : combien de temps accorder à **une** tentative. */
        public ?Duration $startToClose = null,
        /** De la planification à la fin, retentatives comprises : la borne de bout en bout. */
        public ?Duration $scheduleToClose = null,
        /** Silence maximal toléré pendant l'exécution, pour les activités qui battent le cœur. */
        public ?Duration $heartbeat = null,
    ) {
        if (null !== $heartbeat && null !== $startToClose && $heartbeat->isLongerThan($startToClose)) {
            throw new \InvalidArgumentException(\sprintf(
                'Heartbeat timeout (%s) cannot exceed start-to-close (%s): the attempt would end before the first missed heartbeat.',
                $heartbeat,
                $startToClose,
            ));
        }
    }

    /**
     * Aucune borne : le backend applique ses défauts.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Le cas courant : borner une tentative.
     */
    public static function attempt(Duration $startToClose): self
    {
        return new self(startToClose: $startToClose);
    }

    public function withScheduleToStart(?Duration $duration): self
    {
        return new self($duration, $this->startToClose, $this->scheduleToClose, $this->heartbeat);
    }

    public function withStartToClose(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $duration, $this->scheduleToClose, $this->heartbeat);
    }

    public function withScheduleToClose(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $this->startToClose, $duration, $this->heartbeat);
    }

    public function withHeartbeat(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $this->startToClose, $this->scheduleToClose, $duration);
    }

    /**
     * Borne d'**une** exécution, ou le défaut fourni.
     *
     * Temporal refuse une activité sans borne de fermeture : le pont doit en produire une. Cette
     * méthode nomme ce repli, au lieu de le laisser sous forme d'un `?: 30.0` au milieu de la
     * construction de commande.
     */
    public function executionBoundOr(Duration $fallback): Duration
    {
        return $this->startToClose ?? $this->scheduleToClose ?? $fallback;
    }

    /**
     * Vrai si aucune borne n'est posée.
     */
    public function areUnbounded(): bool
    {
        return null === $this->scheduleToStart
            && null === $this->startToClose
            && null === $this->scheduleToClose
            && null === $this->heartbeat;
    }

    /**
     * @return array<string, float>
     */
    public function toMetadata(): array
    {
        $m = [];
        foreach ([
            'schedule_to_start_timeout_seconds' => $this->scheduleToStart,
            'start_to_close_timeout_seconds' => $this->startToClose,
            'schedule_to_close_timeout_seconds' => $this->scheduleToClose,
            'heartbeat_timeout_seconds' => $this->heartbeat,
        ] as $key => $duration) {
            if (null !== $duration) {
                $m[$key] = $duration->toSeconds();
            }
        }

        return $m;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            Duration::fromWireValue($metadata['schedule_to_start_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['start_to_close_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['schedule_to_close_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['heartbeat_timeout_seconds'] ?? null),
        );
    }
}
