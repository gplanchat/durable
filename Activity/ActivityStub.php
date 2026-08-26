<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Awaitable\Awaitable;

/**
 * Proxy de planification côté workflow.
 *
 * Expose uniquement les méthodes marquées #[ActivityMethod] du contrat ;
 * chaque appel retourne un Awaitable et délègue à {@see ActivitySchedulerInterface}.
 *
 * À initialiser dans le constructeur du workflow pour configurer retry et gestion d'erreur via ActivityOptions.
 *
 * @template TActivity of object
 */
final class ActivityStub
{
    /** @var array<string, string> */
    private array $methodToActivityName;

    public function __construct(
        private readonly ActivitySchedulerInterface $scheduler,
        /** @var class-string<TActivity> */
        private readonly string $contractClass,
        ActivityContractResolver $resolver,
        private readonly ?ActivityOptions $options = null,
    ) {
        $this->methodToActivityName = $resolver->resolveActivityMethods($contractClass);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return Awaitable<mixed>
     */
    public function __call(string $name, array $arguments): Awaitable
    {
        $activityName = $this->methodToActivityName[$name] ?? null;
        if (null === $activityName) {
            throw new \BadMethodCallException(\sprintf('Method %s::%s() is not an activity (missing #[ActivityMethod]) or does not exist.', $this->contractClass, $name));
        }

        $payload = $this->argumentsToPayload($name, $arguments);

        return $this->scheduler->scheduleActivity($activityName, $payload, $this->options);
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function argumentsToPayload(string $methodName, array $arguments): array
    {
        $reflection = new \ReflectionMethod($this->contractClass, $methodName);
        $params = $reflection->getParameters();
        $payload = [];
        foreach ($params as $i => $param) {
            $key = $param->getName();
            $payload[$key] = $arguments[$i] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $payload;
    }
}
