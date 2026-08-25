<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Type d'un attribut de recherche, tel que le namespace l'a enregistré.
 *
 * Le serveur **ignore** le type porté par la charge utile et applique celui de son registre : une
 * valeur qui ne lui correspond pas est refusée au démarrage
 * (« invalid value for search attribute … of type Int »). Ce type sert donc à valider la valeur
 * **avant** l'aller-retour, et à annoncer l'intention dans les métadonnées.
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
     * Normalise une valeur PHP vers sa forme JSON attendue, ou refuse.
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
