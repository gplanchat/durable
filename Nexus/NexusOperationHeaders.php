<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Les en-têtes portés jusqu'au handler d'une opération Nexus.
 *
 * **Cet objet n'est pas plus strict que le serveur**, sauf sur un point, et l'écart est mesuré.
 * Sondé sur Temporal 1.31.2, le serveur accepte tel quel une clé vide, une valeur vide, des
 * blancs en bord, un saut de ligne, un espace dans la clé, mille caractères. Refuser tout cela
 * rejetterait des en-têtes qu'il porte sans broncher — l'erreur inverse de celle que
 * {@see \Gplanchat\Durable\TaskQueue} évite.
 *
 * Une seule chose lui échappe, et elle est muette : **il minuscule les clés**. Deux clés qui ne
 * diffèrent que par la casse entrent donc en collision — deux en-têtes entrent, un seul sort, sans
 * erreur et sans rien dans l'historique pour dire lequel a sauté.
 *
 * D'où les deux seules règles d'ici, toutes deux tirées de cette observation :
 *
 * - **la clé est minusculée à la construction**, pour que ce que l'appelant tient soit ce que le
 *   serveur gardera. C'est une coercition, pas un refus : `X-Correlation` est un en-tête
 *   parfaitement valide, il *est* simplement `x-correlation` ;
 * - **une collision est refusée**, parce que l'appelant demande là quelque chose que le serveur ne
 *   sait pas faire et ne dira pas.
 */
final readonly class NexusOperationHeaders
{
    /** @param array<string, string> $headers déjà minusculés et sans collision */
    private function __construct(
        private array $headers,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function of(array $headers): self
    {
        $lowered = [];
        $origins = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (isset($origins[$lowerKey])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Nexus headers "%s" and "%s" collide on "%s": the server lowercases keys, so only '
                    . 'one of the two would survive — and it would not say which.',
                    $origins[$lowerKey],
                    (string) $key,
                    $lowerKey,
                ));
            }

            $origins[$lowerKey] = (string) $key;
            $lowered[$lowerKey] = $value;
        }

        return new self($lowered);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->headers;
    }

    public function isEmpty(): bool
    {
        return [] === $this->headers;
    }
}
