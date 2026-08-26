<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Une récurrence : quand relancer une exécution.
 *
 * Une expression cron est une grammaire, pas une chaîne. Passée telle quelle, une faute de
 * frappe ne se manifeste qu'au retour du serveur — c'est-à-dire en production, à la première
 * tentative de démarrage. Elle est donc validée à la construction.
 *
 * Trois formes, celles que le serveur Temporal accepte :
 * - cinq champs — `minute heure jour-du-mois mois jour-de-semaine` ;
 * - un raccourci — `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly` ;
 * - un intervalle — `@every 90s`, `@every 1h30m`.
 *
 * Chacune peut être préfixée d'un fuseau : `CRON_TZ=Europe/Paris 0 9 * * 1-5`.
 *
 * La validation reproduit celle du serveur, sondée expression par expression : nombre de champs,
 * caractères, bornes, et **atteignabilité** — le serveur refuse `0 0 31 4 *` (« no time can be
 * found to satisfy the schedule »), avril n'ayant que trente jours.
 *
 * `?` y est un synonyme de `*`, accepté dans n'importe quel champ. Le jour de semaine va de 0 à
 * 6 : `7` pour dimanche est refusé.
 */
final readonly class CronSchedule
{
    private const SHORTCUTS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];

    /** Bornes des cinq champs, dans l'ordre. */
    private const FIELDS = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day of month' => [1, 31],
        'month' => [1, 12],
        'day of week' => [0, 6],
    ];

    private const MONTH_NAMES = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    private const DAY_NAMES = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

    private function __construct(
        private string $expression,
    ) {}

    public static function parse(string $expression): self
    {
        $trimmed = trim($expression);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('A cron schedule cannot be empty.');
        }

        [$timeZonePrefix, $schedule] = self::splitTimeZone($trimmed);
        self::assertValidSchedule($schedule);

        return new self($timeZonePrefix . $schedule);
    }

    /**
     * Coercition de frontière : accepte ce que l'appelant a sous la main.
     */
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::parse($value);
    }

    public static function hourly(): self
    {
        return new self('@hourly');
    }

    public static function daily(): self
    {
        return new self('@daily');
    }

    public static function weekly(): self
    {
        return new self('@weekly');
    }

    public static function monthly(): self
    {
        return new self('@monthly');
    }

    public static function yearly(): self
    {
        return new self('@yearly');
    }

    /**
     * Toutes les {@code $interval}. Le serveur ne descend pas sous la seconde.
     */
    public static function every(Duration $interval): self
    {
        $seconds = (int) round($interval->toSeconds());
        if ($seconds < 1) {
            throw new \InvalidArgumentException(\sprintf('A cron interval must be at least one second, %s given.', $interval));
        }

        return new self('@every ' . self::toGoDuration($seconds));
    }

    /**
     * Chaque jour à l'heure dite.
     */
    public static function dailyAt(int $hour, int $minute = 0): self
    {
        self::assertInRange('hour', $hour, 0, 23);
        self::assertInRange('minute', $minute, 0, 59);

        return new self(\sprintf('%d %d * * *', $minute, $hour));
    }

    /**
     * Le même horaire, lu dans un autre fuseau.
     *
     * Sans fuseau, le serveur interprète l'expression en UTC — ce qui n'est presque jamais ce
     * qu'on veut d'un « tous les jours à 9 h ».
     */
    public function inTimeZone(\DateTimeZone|string $timeZone): self
    {
        $name = $timeZone instanceof \DateTimeZone ? $timeZone->getName() : $timeZone;
        if (1 !== preg_match('/^[A-Za-z0-9_+\-\/]+$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid time zone name "%s".', $name));
        }

        return new self(\sprintf('CRON_TZ=%s %s', $name, self::splitTimeZone($this->expression)[1]));
    }

    public function timeZone(): ?string
    {
        $prefix = self::splitTimeZone($this->expression)[0];

        return '' === $prefix ? null : rtrim(substr($prefix, \strlen('CRON_TZ=')));
    }

    public function toExpression(): string
    {
        return $this->expression;
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string} préfixe de fuseau (vide ou `CRON_TZ=… `), puis l'horaire
     */
    private static function splitTimeZone(string $expression): array
    {
        if (1 === preg_match('/^(CRON_TZ=\S+\s+)(.*)$/', $expression, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return ['', $expression];
    }

    private static function assertValidSchedule(string $schedule): void
    {
        if (\in_array(strtolower($schedule), self::SHORTCUTS, true)) {
            return;
        }

        if (str_starts_with($schedule, '@every ')) {
            self::assertValidInterval(trim(substr($schedule, \strlen('@every '))));

            return;
        }

        if (str_starts_with($schedule, '@')) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown cron shortcut "%s". Supported: %s, or "@every <duration>".',
                $schedule,
                implode(', ', self::SHORTCUTS),
            ));
        }

        $fields = preg_split('/\s+/', $schedule) ?: [];
        if (5 !== \count($fields)) {
            throw new \InvalidArgumentException(\sprintf(
                'A cron schedule has 5 fields (minute hour day-of-month month day-of-week), %d given in "%s". '
                . 'Six-field expressions (Quartz, with seconds) are not supported.',
                \count($fields),
                $schedule,
            ));
        }

        $expanded = [];
        foreach (array_combine(array_keys(self::FIELDS), $fields) as $name => $field) {
            $expanded[$name] = self::expandField($name, $field, self::FIELDS[$name][0], self::FIELDS[$name][1]);
        }

        self::assertReachable($expanded['day of month'], $expanded['month'], $schedule);
    }

    /**
     * Développe un champ en l'ensemble des valeurs qu'il désigne, ou null s'il les couvre toutes.
     *
     * @return list<int>|null
     */
    private static function expandField(string $name, string $field, int $min, int $max): ?array
    {
        $values = [];
        $coversAll = false;

        foreach (explode(',', $field) as $part) {
            if ('' === $part) {
                throw new \InvalidArgumentException(\sprintf('Empty %s field entry in "%s".', $name, $field));
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                if (1 !== preg_match('/^\d+$/', $stepText) || 0 === (int) $stepText) {
                    throw new \InvalidArgumentException(\sprintf('Invalid step "%s" in %s field.', $stepText, $name));
                }
                $step = (int) $stepText;
            }

            // `?` est un synonyme de `*` côté serveur, dans n'importe quel champ.
            if ('*' === $part || '?' === $part) {
                if (1 === $step) {
                    $coversAll = true;
                }
                for ($v = $min; $v <= $max; $v += $step) {
                    $values[] = $v;
                }

                continue;
            }

            $bounds = explode('-', $part, 2);
            $from = self::toNumber($name, $bounds[0]);
            $to = 2 === \count($bounds) ? self::toNumber($name, $bounds[1]) : $from;
            self::assertInRange($name, $from, $min, $max);
            self::assertInRange($name, $to, $min, $max);
            if ($from > $to) {
                throw new \InvalidArgumentException(\sprintf('Reversed range "%s" in %s field.', $part, $name));
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[] = $v;
            }
        }

        return $coversAll ? null : array_values(array_unique($values));
    }

    /**
     * Nombre de jours du mois, février compté bissextile : une échéance au 29 février existe.
     */
    private const DAYS_IN_MONTH = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    /**
     * @param list<int>|null $daysOfMonth
     * @param list<int>|null $months
     */
    private static function assertReachable(?array $daysOfMonth, ?array $months, string $schedule): void
    {
        if (null === $daysOfMonth || null === $months) {
            return;
        }

        foreach ($months as $month) {
            foreach ($daysOfMonth as $day) {
                if ($day <= self::DAYS_IN_MONTH[$month]) {
                    return;
                }
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            'No time can satisfy the cron schedule "%s": none of the days of month exist in the selected months.',
            $schedule,
        ));
    }

    /**
     * Un nom de mois ou de jour vaut son rang ; le serveur les accepte dans leur champ.
     */
    private static function toNumber(string $name, string $value): int
    {
        $upper = strtoupper($value);
        if ('month' === $name && false !== ($index = array_search($upper, self::MONTH_NAMES, true))) {
            return $index + 1;
        }
        if ('day of week' === $name && false !== ($index = array_search($upper, self::DAY_NAMES, true))) {
            return $index;
        }
        if (1 !== preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s value "%s".', $name, $value));
        }

        return (int) $value;
    }

    private static function assertInRange(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(\sprintf(
                'Cron %s must be between %d and %d, %d given.',
                $name,
                $min,
                $max,
                $value,
            ));
        }
    }

    private static function assertValidInterval(string $interval): void
    {
        if (1 !== preg_match('/^(\d+(\.\d+)?(ns|us|ms|s|m|h))+$/', $interval)) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid "@every" interval "%s": expected a Go duration such as 90s, 5m or 1h30m.',
                $interval,
            ));
        }
    }

    private static function toGoDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        $text = ($hours > 0 ? $hours . 'h' : '') . ($minutes > 0 ? $minutes . 'm' : '') . ($rest > 0 ? $rest . 's' : '');

        return '' === $text ? '0s' : $text;
    }
}
