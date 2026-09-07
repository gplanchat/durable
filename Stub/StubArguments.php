<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Stub;

/**
 * What a `__call` stub does with the arguments it was handed.
 *
 * The three stubs — activity, Nexus operation, child workflow — all call a contract method through
 * `__call`, and all have to turn the arguments they receive into a named payload, because named is
 * how it travels in the journal. Each did it on its own side, identically, and with the same flaw.
 *
 * **The flaw: named arguments were silently lost.** PHP passes named arguments to `__call` in an
 * array with **string keys**; the matching was done by index (`$arguments[$i]`), no index ever
 * answered, and *every* parameter fell back to its default value. No exception, no trace. A child
 * workflow started that way set off with an empty prompt and waited for a message that would never
 * come.
 *
 * **The second flaw, of the same order: `??` confuses "absent" and "null".** Explicitly passing
 * `null` to a nullable parameter gave its default value rather than `null` — that is, the opposite
 * of what was asked for. Hence `array_key_exists` rather than `??`.
 *
 * **And what PHP refuses, the stub refuses.** On an ordinary call, PHP throws on an unknown named
 * argument (`Unknown named parameter`), on a missing required argument (`ArgumentCountError`) and
 * on a parameter served twice, positionally then by name (`Named parameter $x overwrites previous
 * argument`). A stub that swallows any of the three makes a mistake indistinguishable from an
 * intended value — and sends it travelling all the way into the journal, where it will be replayed
 * identically. All three therefore throw here too. The type differs from PHP's —
 * `\BadMethodCallException` rather than `\Error` or `\ArgumentCountError` — because the call goes
 * through `__call`: it is the exception the SPL reserves for a method called wrongly, and it stays
 * catchable.
 */
final class StubArguments
{
    private function __construct() {}

    /**
     * @param array<int|string, mixed> $arguments as `__call` received them: the positional ones
     *                                            under indices, the named ones under their name
     *
     * @return array<string, mixed> the named payload, one contract parameter per key
     *
     * @throws \BadMethodCallException if a named argument matches no parameter, if a required
     *                                 parameter is not supplied, or if a parameter is served both
     *                                 positionally and by name
     */
    public static function toPayload(\ReflectionFunctionAbstract $method, array $arguments): array
    {
        $payload = [];
        $connus = [];

        foreach ($method->getParameters() as $i => $param) {
            $name = $param->getName();
            $connus[$name] = true;

            // A variadic has neither a default value nor an obligation: it cannot be missing, and
            // it has no place of its own in a named payload.
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

            // The fall-back-to-`null` that used to be here crossed the journal without a word,
            // and replayed identically on every pass: the parameter is required, its absence is a
            // call error, and a non-nullable type would have refused it on arrival anyway.
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
