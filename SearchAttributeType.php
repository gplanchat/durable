<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * The type of a search attribute, as the namespace registered it.
 *
 * The server **ignores** the type carried by the payload and applies the one from its registry:
 * a value that does not match it is refused at start time
 * ("invalid value for search attribute … of type Int"). So this type serves to validate the
 * value **before** the round trip, and to state the intent in the metadata.
 */
enum SearchAttributeType: string
{
    case Keyword = 'Keyword';
    case Text = 'Text';
    case Int = 'Int';
    case Double = 'Double';
    case Bool = 'Bool';
    case Datetime = 'Datetime';
    case KeywordList = 'KeywordList';

    /**
     * Normalises a PHP value into its expected JSON form, or refuses it.
     */
    public function normalize(string $attribute, mixed $value): mixed
    {
        return match ($this) {
            self::Keyword, self::Text => \is_string($value)
                ? $value
                : throw $this->reject($attribute, $value, 'a string'),
            self::Int => \is_int($value)
                ? $value
                : throw $this->reject($attribute, $value, 'an integer'),
            self::Double => \is_int($value) || \is_float($value)
                ? (float) $value
                : throw $this->reject($attribute, $value, 'a number'),
            self::Bool => \is_bool($value)
                ? $value
                : throw $this->reject($attribute, $value, 'a boolean'),
            self::Datetime => self::normalizeDatetime($attribute, $value),
            self::KeywordList => self::normalizeKeywordList($attribute, $value),
        };
    }

    private static function normalizeDatetime(string $attribute, mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        if (\is_string($value) && false !== strtotime($value)) {
            return $value;
        }

        throw self::Datetime->reject($attribute, $value, 'a DateTimeInterface or an RFC 3339 string');
    }

    /**
     * @return list<string>
     */
    private static function normalizeKeywordList(string $attribute, mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value) === false) {
            throw self::KeywordList->reject($attribute, $value, 'a list of strings');
        }
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw self::KeywordList->reject($attribute, $value, 'a list of strings');
            }
        }

        return $value;
    }

    private function reject(string $attribute, mixed $value, string $expected): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            'Search attribute "%s" is of type %s and needs %s, %s given.',
            $attribute,
            $this->value,
            $expected,
            get_debug_type($value),
        ));
    }
}
