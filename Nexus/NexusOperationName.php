<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Le nom de l'opération appelée sur le service Nexus.
 *
 * **Cet objet est plus strict que le serveur, et c'est délibéré** — comme
 * {@see \Gplanchat\Durable\TaskQueue}, et à l'inverse de {@see NexusEndpoint}.
 *
 * Sondé sur Temporal 1.31.2 (tâche 1.1) : le serveur ne valide **rien** ici. Vide, un espace,
 * blancs en bord, tabulation interne, caractère de contrôle, mille caractères — tout est accepté,
 * et `NEXUS_OPERATION_SCHEDULED` enregistre le nom verbatim. Rien ne suit : l'opération reste
 * planifiée, à attendre un gestionnaire dont le nom ne correspondra jamais, sans une ligne
 * d'erreur. C'est exactement la panne muette d'une file de tâches mal nommée, et seule une règle
 * plus stricte que le serveur peut l'empêcher.
 *
 * Ce qui est refusé se limite à ce qui ne peut être qu'une faute : nom vide ou entièrement blanc,
 * blancs en bord, caractère de contrôle. **Aucune borne de longueur et aucun alphabet ne sont
 * imposés** — le serveur n'en a montré aucun, et la tâche 1.4 interdit d'écrire un invariant qui
 * n'a pas été observé. Un point, une barre oblique ou une majuscule sont donc des noms légitimes.
 *
 * Comme partout ailleurs, la faute de frappe qui reste un nom plausible n'est pas attrapée :
 * seul un registre des opérations réellement servies le pourrait.
 */
final readonly class NexusOperationName
{
    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A Nexus operation name is not set.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A Nexus operation name cannot be blank: the server would accept it and no handler would ever match it.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus operation name "%s" has leading or trailing whitespace. The server records it as given, so a handler registered under the trimmed name would never be matched.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus operation name "%s" contains a control character; such names are invisible in logs and impossible to match by eye.',
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
