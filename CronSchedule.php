<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * A recurrence: when to start an execution again.
 *
 * A cron expression is a grammar, not a string. Passed through as it is, a typo only shows up
 * in the server's answer — that is, in production, on the first attempt to start. So it is
 * validated at construction time.
 *
 * Three forms, the ones the Temporal server accepts:
 * - five fields — `minute hour day-of-month month day-of-week`;
 * - a shortcut — `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly`;
 * - an interval — `@every 90s`, `@every 1h30m`.
 *
 * Each of them can be prefixed with a time zone: `CRON_TZ=Europe/Paris 0 9 * * 1-5`.
 *
 * The validation reproduces the server's, probed expression by expression: number of fields,
 * characters, bounds, and **reachability** — the server refuses `0 0 31 4 *` ("no time can be
 * found to satisfy the schedule"), April having only thirty days.
 *
 * `?` is a synonym of `*` there, accepted in any field. The day of week runs from 0 to
 * 6: `7` for Sunday is refused.
 */
final readonly class CronSchedule
{
    private const SHORTCUTS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];

    /** Bounds of the five fields, in order. */
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
     * Boundary coercion: accepts whatever the caller has at hand.
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
     * Every {@code $interval}. The server does not go below the second.
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
     * Every day at the stated time.
     */
    public static function dailyAt(int $hour, int $minute = 0): self
    {
        self::assertInRange('hour', $hour, 0, 23);
        self::assertInRange('minute', $minute, 0, 59);

        return new self(\sprintf('%d %d * * *', $minute, $hour));
    }

    /**
     * The same schedule, read in another time zone.
     *
     * Without a time zone the server reads the expression in UTC — which is almost never what
     * "every day at 9am" is meant to say.
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
     * @return array{0: string, 1: string} time zone prefix (empty or `CRON_TZ=… `), then the schedule
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
     * Expands a field into the set of values it names, or null when it covers them all.
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

            // `?` is a synonym of `*` on the server side, in any field.
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
     * Number of days per month, February counted as a leap one: a February 29th due time exists.
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
     * A month or day name stands for its rank; the server accepts them in their own field.
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
