<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Le nom d'une file de tâches : où le travail est déposé, et donc où un worker doit venir le
 * chercher.
 *
 * Le serveur n'exige presque rien — non vide, mille caractères au plus. Sondé, il accepte `" "`,
 * les espaces en bord, les tabulations et les sauts de ligne. Or une file mal nommée ne produit
 * aucune erreur : le travail y est déposé et personne ne vient le chercher. L'exécution reste
 * simplement en attente, sans rien dans les logs.
 *
 * Cet objet est donc **plus strict que le serveur** sur ce qui ne peut être qu'une faute :
 * blancs en bord, nom entièrement blanc, caractères de contrôle. Il n'attrape pas la faute de
 * frappe qui reste un nom valide (`durable-activites` pour `durable-activities`) — seul un
 * registre des files réellement servies le pourrait.
 */
final readonly class TaskQueue
{
    /** Limite du serveur, sondée : 1000 accepté, 1001 refusé (« taskQueue length exceeds limit »). */
    public const MAX_LENGTH = 1000;

    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A task queue name cannot be empty.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A task queue name cannot be blank: the server would accept it and no worker would ever find it.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Task queue name "%s" has leading or trailing whitespace. The server keeps it, so a worker polling the trimmed name would never be matched.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Task queue name "%s" contains a control character; such names are invisible in logs and impossible to match by eye.',
                addcslashes($name, "\0..\37\177"),
            ));
        }
        if (\strlen($name) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A task queue name is at most %d bytes, %d given.',
                self::MAX_LENGTH,
                \strlen($name),
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
