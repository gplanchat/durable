<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Le namespace : la frontière d'isolation dans laquelle vivent exécutions, files et attributs de
 * recherche.
 *
 * Nommé `WorkflowNamespace` faute de mieux — `namespace` est un mot réservé du langage.
 *
 * Contrairement à {@see TaskQueue}, une erreur ici ne passe pas inaperçue : le serveur répond
 * `NOT_FOUND, Namespace "…" is not found`, un namespace devant exister avant usage. Cet objet
 * apporte donc surtout du **typage** — namespace et file de tâches sont deux chaînes voisines
 * dans les mêmes constructeurs, et les intervertir ne se voyait qu'à l'exécution.
 *
 * Sondé : le serveur n'exige que « non vide ». Il accepte espaces, majuscules, accents,
 * tabulations et plus de 255 caractères. Il est en revanche **sensible à la casse** et aux
 * blancs : `DURABLE-TEST` et `durable-test ` sont des namespaces distincts de `durable-test`,
 * et donc introuvables.
 */
final readonly class WorkflowNamespace
{
    /** Namespace système du serveur ; il n'accueille pas de workflow applicatif. */
    public const SYSTEM = 'temporal-system';

    private function __construct(
        private string $name,
    ) {
    }

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A namespace cannot be empty.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A namespace cannot be blank.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Namespace "%s" has leading or trailing whitespace. The server compares names byte for byte, so it would report it as not found.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Namespace "%s" contains a control character.',
                addcslashes($name, "\0..\37\177"),
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

    public static function fromNullable(self|string|null $value): ?self
    {
        return null === $value || '' === $value ? null : self::from($value);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Le namespace système du serveur, où aucun workflow applicatif n'a sa place.
     */
    public function isSystem(): bool
    {
        return self::SYSTEM === $this->name;
    }

    /**
     * Comparaison sensible à la casse, comme le serveur.
     */
    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
