<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Le nom d'un endpoint Nexus : où une opération est routée.
 *
 * **Cet objet n'est pas plus strict que le serveur, et c'est délibéré** — l'inverse du choix fait
 * pour {@see \Gplanchat\Durable\TaskQueue}. Une file mal nommée est acceptée par le serveur puis
 * n'est jamais servie : le travail y dort, rien n'apparaît dans les logs, et seule une règle plus
 * stricte que le serveur peut l'empêcher. Un endpoint mal nommé n'a pas cette panne muette : le
 * serveur le refuse net à la création. Inventer ici une règle supplémentaire ne préviendrait donc
 * aucune faute — elle ne ferait que rejeter des noms parfaitement valides.
 *
 * La règle est celle que le serveur énonce lui-même, observée sur Temporal 1.31.2 (tâche 1.1) et
 * épinglée par `NexusEndpointNameRulesTest` : `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200
 * caractères. Une conséquence de ce motif surprend et mérite d'être dite : il exige un premier
 * **et** un dernier caractère, donc une lettre seule (`a`) est refusée.
 *
 * La seule distinction conservée est celle du serveur : un nom vide n'est pas *malformé*, il est
 * *absent*, et les deux méritent des messages différents.
 */
final readonly class NexusEndpoint
{
    /** Limite du serveur, sondée : 200 accepté, 201 refusé. */
    public const MAX_LENGTH = 200;

    /** Le motif que le serveur énonce dans son propre message de refus. */
    private const PATTERN = '/^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$/';

    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A Nexus endpoint name is not set.');
        }
        if (\strlen($name) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus endpoint name is at most %d characters, %d given.',
                self::MAX_LENGTH,
                \strlen($name),
            ));
        }
        if (1 !== preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Nexus endpoint name "%s" does not match %s: it starts with a letter, continues with '
                . 'letters, digits or hyphens, and ends with a letter or a digit — which also means a '
                . 'single character is too short.',
                addcslashes($name, "\0..\37\177"),
                '^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$',
            ));
        }

        return new self($name);
    }

    /**
     * Coercition de frontière : accepte ce que l'appelant a sous la main.
     */
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::named($value);
    }

    /**
     * Depuis une valeur de configuration éventuellement absente.
     */
    public static function fromNullable(self|string|null $value): ?self
    {
        return null === $value || '' === $value ? null : self::from($value);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
