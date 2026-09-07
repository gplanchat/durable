<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * The search attributes of an execution: what it will be findable by.
 *
 * This used to be a free-form `?array` — and, worse, an array that never reached the server: it
 * was logged in the metadata then forgotten, no command ever setting it.
 *
 * Three server rules, probed one by one, two of which are checkable here:
 * - the value must match the registered type ({@see SearchAttributeType}) — checked;
 * - some system attributes are read-only ({@see READ_ONLY}) — checked;
 * - the attribute must be registered in the namespace — **not** checkable locally, that would
 *   require reading the namespace registry. The server then answers
 *   "has no mapping defined for search attribute".
 */
final readonly class SearchAttributes
{
    /**
     * Attributes the server fills in itself and refuses on write, collected one by one:
     * "… attribute can't be set in SearchAttributes".
     */
    public const READ_ONLY = [
        'CloseTime', 'ExecutionDuration', 'ExecutionStatus', 'ExecutionTime', 'HistoryLength',
        'HistorySizeBytes', 'ParentRunId', 'ParentWorkflowId', 'RootRunId', 'RootWorkflowId',
        'RunId', 'StartTime', 'StateTransitionCount', 'TaskQueue', 'WorkflowId', 'WorkflowType',
    ];

    /**
     * @param array<string, array{type: SearchAttributeType, value: mixed}> $attributes
     */
    private function __construct(
        private array $attributes = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function keyword(string $name, string $value): self
    {
        return $this->with($name, SearchAttributeType::Keyword, $value);
    }

    public function text(string $name, string $value): self
    {
        return $this->with($name, SearchAttributeType::Text, $value);
    }

    public function int(string $name, int $value): self
    {
        return $this->with($name, SearchAttributeType::Int, $value);
    }

    public function double(string $name, int|float $value): self
    {
        return $this->with($name, SearchAttributeType::Double, $value);
    }

    public function bool(string $name, bool $value): self
    {
        return $this->with($name, SearchAttributeType::Bool, $value);
    }

    public function datetime(string $name, \DateTimeInterface|string $value): self
    {
        return $this->with($name, SearchAttributeType::Datetime, $value);
    }

    /**
     * @param list<string> $values
     */
    public function keywordList(string $name, array $values): self
    {
        return $this->with($name, SearchAttributeType::KeywordList, $values);
    }

    public function with(string $name, SearchAttributeType $type, mixed $value): self
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A search attribute name cannot be blank.');
        }
        if (\in_array($name, self::READ_ONLY, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Search attribute "%s" is maintained by the server and cannot be set.',
                $name,
            ));
        }

        return new self([...$this->attributes, $name => ['type' => $type, 'value' => $type->normalize($name, $value)]]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->attributes;
    }

    public function has(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function typeOf(string $name): ?SearchAttributeType
    {
        return $this->attributes[$name]['type'] ?? null;
    }

    /**
     * @return array<string, mixed> normalised values, keyed by name
     */
    public function toValues(): array
    {
        return array_map(static fn(array $entry): mixed => $entry['value'], $this->attributes);
    }

    /**
     * @return array<string, array{type: string, value: mixed}>
     */
    public function toMetadata(): array
    {
        return array_map(
            static fn(array $entry): array => ['type' => $entry['type']->value, 'value' => $entry['value']],
            $this->attributes,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $attributes = self::none();
        foreach ($metadata as $name => $entry) {
            if (!\is_array($entry) || !isset($entry['type'])) {
                continue;
            }
            $type = SearchAttributeType::tryFrom((string) $entry['type']);
            if (null !== $type) {
                $attributes = $attributes->with((string) $name, $type, $entry['value'] ?? null);
            }
        }

        return $attributes;
    }
}
