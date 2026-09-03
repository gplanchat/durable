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
 * **Et ce que PHP refuse, le stub le refuse.** Sur un appel ordinaire, PHP lève sur un argument
 * nommé inconnu (`Unknown named parameter`), sur un argument requis manquant (`ArgumentCountError`)
 * et sur un paramètre servi deux fois, en positionnel puis en nommé (`Named parameter $x overwrites
 * previous argument`). Un stub qui avale l'un des trois rend une faute indiscernable d'une valeur
 * voulue — et la fait voyager jusque dans le journal, où elle sera rejouée à l'identique. Les trois
 * lèvent donc ici aussi. Le type diffère de celui de PHP — `\BadMethodCallException` plutôt que
 * `\Error` ou `\ArgumentCountError` — parce que l'appel passe par `__call` : c'est l'exception que
 * la SPL réserve à une méthode appelée de travers, et elle reste rattrapable.
 */
final class StubArguments
{
    private function __construct() {}

    /**
     * @param array<int|string, mixed> $arguments tels que `__call` les a reçus : les positionnels
     *                                            sous des indices, les nommés sous leur nom
     *
     * @return array<string, mixed> la charge nommée, un paramètre du contrat par clé
     *
     * @throws \BadMethodCallException si un argument nommé ne correspond à aucun paramètre, si un
     *                                 paramètre requis n'est pas fourni, ou si un paramètre est
     *                                 servi à la fois en positionnel et en nommé
     */
    public static function toPayload(\ReflectionFunctionAbstract $method, array $arguments): array
    {
        $payload = [];
        $connus = [];

        foreach ($method->getParameters() as $i => $param) {
            $name = $param->getName();
            $connus[$name] = true;

            // Un variadique n'a ni valeur par défaut ni obligation : il ne peut pas manquer, et
            // il n'a pas de place à lui dans une charge nommée.
            if ($param->isVariadic()) {
                continue;
            }

            $parPosition = \array_key_exists($i, $arguments);
            $parNom = \array_key_exists($name, $arguments);

            if ($parPosition && $parNom) {
                throw new \BadMethodCallException(\sprintf(
                    'Parameter $%s of %s() was given both positionally and by name.',
                    $name,
                    self::describe($method),
                ));
            }

            if ($parPosition) {
                $payload[$name] = $arguments[$i];

                continue;
            }

            if ($parNom) {
                $payload[$name] = $arguments[$name];

                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $payload[$name] = $param->getDefaultValue();

                continue;
            }

            // Le retomber-sur-`null` d'ici traversait le journal sans un mot, et se rejouait à
            // l'identique à chaque passe : le paramètre est requis, son absence est une faute
            // d'appel, et un type non nullable l'aurait de toute façon refusée à l'arrivée.
            throw new \BadMethodCallException(\sprintf(
                'Missing required argument $%s for %s().',
                $name,
                self::describe($method),
            ));
        }

        foreach ($arguments as $key => $_) {
            if (\is_string($key) && !isset($connus[$key])) {
                throw new \BadMethodCallException(\sprintf(
                    'Unknown named parameter $%s for %s(); known parameters: %s.',
                    $key,
                    self::describe($method),
                    implode(', ', array_map(static fn(string $n): string => '$' . $n, array_keys($connus))),
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
