<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Stub;

/**
 * Ce qu'un stub `__call` fait des arguments qu'on lui a passés.
 *
 * Les trois stubs — activité, opération Nexus, workflow enfant — appellent tous une méthode d'un
 * contrat par `__call`, et doivent tous transformer les arguments reçus en une charge nommée, parce
 * que c'est nommé que ça voyage dans le journal. Ils le faisaient chacun de leur côté, à
 * l'identique, et avec le même défaut.
 *
 * **Le défaut : les arguments nommés étaient silencieusement perdus.** PHP passe les arguments
 * nommés à `__call` dans un tableau à **clés de chaînes** ; l'appariement se faisait par indice
 * (`$arguments[$i]`), aucun indice ne répondait, et *tous* les paramètres retombaient sur leur
 * valeur par défaut. Sans exception, sans trace. Un workflow enfant démarré ainsi partait avec un
 * prompt vide et attendait un message qui ne viendrait jamais.
 *
 * **Le second défaut, du même ordre : `??` confond « absent » et « null ».** Passer explicitement
 * `null` à un paramètre nullable donnait sa valeur par défaut plutôt que `null` — c'est-à-dire
 * l'inverse de ce qui était demandé. D'où `array_key_exists` plutôt que `??`.
 *
 * **Et un argument nommé inconnu lève**, au lieu de disparaître. PHP fait de même sur un appel
 * ordinaire (`Unknown named parameter`) ; un stub qui l'avale rendrait une faute de frappe
 * indiscernable d'une valeur par défaut voulue.
 */
final class StubArguments
{
    private function __construct()
    {
    }

    /**
     * @param array<int|string, mixed> $arguments tels que `__call` les a reçus : les positionnels
     *                                            sous des indices, les nommés sous leur nom
     *
     * @return array<string, mixed> la charge nommée, un paramètre du contrat par clé
     *
     * @throws \BadMethodCallException si un argument nommé ne correspond à aucun paramètre
     */
    public static function toPayload(\ReflectionFunctionAbstract $method, array $arguments): array
    {
        $payload = [];
        $connus = [];

        foreach ($method->getParameters() as $i => $param) {
            $name = $param->getName();
            $connus[$name] = true;

            if (\array_key_exists($i, $arguments)) {
                $payload[$name] = $arguments[$i];

                continue;
            }

            if (\array_key_exists($name, $arguments)) {
                $payload[$name] = $arguments[$name];

                continue;
            }

            $payload[$name] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        }

        foreach ($arguments as $key => $_) {
            if (\is_string($key) && !isset($connus[$key])) {
                throw new \BadMethodCallException(\sprintf(
                    'Unknown named parameter $%s for %s(); known parameters: %s.',
                    $key,
                    self::describe($method),
                    implode(', ', array_map(static fn (string $n): string => '$' . $n, array_keys($connus))),
                ));
            }
        }

        return $payload;
    }

    private static function describe(\ReflectionFunctionAbstract $method): string
    {
        return $method instanceof \ReflectionMethod
            ? $method->getDeclaringClass()->getName() . '::' . $method->getName()
            : $method->getName();
    }
}
