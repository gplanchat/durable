<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Duration;

/**
 * Les bornes temporelles d'une opération Nexus, prises ensemble.
 *
 *     planifiée ──schedule-to-start──▶ démarrée ──start-to-close──▶ terminée
 *     └────────────────── schedule-to-close ─────────────────────────┘
 *
 * Même découpage que {@see \Gplanchat\Durable\Activity\ActivityTimeouts}, à ceci près qu'il n'y a
 * pas de battement de cœur : une opération Nexus est servie par un autre système, qui ne rend pas
 * de signe de vie intermédiaire.
 *
 * **Cet objet est plus strict que le serveur, et sur un point précis.** Sondé (§1.3), le serveur
 * accepte une sous-borne plus grande que `schedule-to-close` et la **rabote en silence** : demander
 * 60 s de `start-to-close` sous 10 s d'enveloppe fait enregistrer 10 s, sans erreur, sans trace.
 * L'appelant garde une borne qu'il croit avoir. La combinaison est donc refusée ici, à la
 * construction, où elle se lit.
 *
 * Une enveloppe **infinie** ne rabote rien : sur le fil elle s'écrit `0`, que Temporal lit « pas de
 * borne » et non « zéro seconde ». {@see Duration::infinity()} le dit sans ce déguisement.
 *
 * Pas d'`executionBoundOr()` ici, contrairement aux activités : §2.2 le conditionnait à ce que le
 * serveur exige une borne de fermeture, et la sonde a montré qu'il n'en exige aucune — une
 * commande sans aucune des trois est acceptée, et l'événement n'en enregistre aucune.
 */
final readonly class NexusOperationTimeouts
{
    public function __construct(
        /** De la planification à la fin : l'enveloppe, qui borne les deux autres. */
        public ?Duration $scheduleToClose = null,
        /** De la planification au démarrage effectif chez le gestionnaire. */
        public ?Duration $scheduleToStart = null,
        /** Du démarrage à la fin. */
        public ?Duration $startToClose = null,
    ) {
        $this->refuseSilentClamp('schedule-to-start', $scheduleToStart);
        $this->refuseSilentClamp('start-to-close', $startToClose);
    }

    /**
     * Aucune borne : le serveur n'en applique aucune non plus, il enregistre la commande telle
     * quelle.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Le cas courant : borner l'opération de bout en bout.
     */
    public static function within(Duration $scheduleToClose): self
    {
        return new self(scheduleToClose: $scheduleToClose);
    }

    public function withScheduleToClose(?Duration $duration): self
    {
        return new self($duration, $this->scheduleToStart, $this->startToClose);
    }

    public function withScheduleToStart(?Duration $duration): self
    {
        return new self($this->scheduleToClose, $duration, $this->startToClose);
    }

    public function withStartToClose(?Duration $duration): self
    {
        return new self($this->scheduleToClose, $this->scheduleToStart, $duration);
    }

    public function areUnbounded(): bool
    {
        return null === $this->scheduleToClose
            && null === $this->scheduleToStart
            && null === $this->startToClose;
    }

    private function refuseSilentClamp(string $name, ?Duration $bound): void
    {
        $envelope = $this->scheduleToClose;
        if (null === $bound || null === $envelope || $envelope->isInfinite()) {
            return;
        }
        if (!$bound->isLongerThan($envelope)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'A %s bound of %s cannot exceed the schedule-to-close envelope of %s: the server would clamp it down to %s without an error, and the operation would end sooner than asked.',
            $name,
            $bound,
            $envelope,
            $envelope,
        ));
    }
}
