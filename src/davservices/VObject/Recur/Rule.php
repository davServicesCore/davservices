<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\VObject\Recur;

use DavServices\VObject\ParseError;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;

/**
 * A recurrence rule, read and written (RFC 5545 §3.3.10, R-RRULE-01).
 *
 *     $rule = Rule::decode('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1');
 *
 *     recur = recur-rule-part *( ";" recur-rule-part )
 *
 * **This reads and writes a rule. It does not expand one.** The two are
 * separate jobs, and this library has kept them apart all through P4: the
 * lexer reads lines before anything holds them, and the model holds an object
 * before anything judges it. A rule that is read wrongly cannot be expanded
 * rightly, and the grammar is long enough to stand on its own — fourteen rule
 * parts, nine of them lists with ranges, and six sentences about which may
 * stand beside which.
 *
 * ## Liberal in what it accepts, strict in what it writes
 *
 * §3.3.10 says both in one sentence, pulling in opposite directions:
 *
 * > Compliant applications MUST accept rule parts ordered in any sequence,
 * > but to ensure backward compatibility with applications that pre-date this
 * > revision of iCalendar the FREQ rule part MUST be the first rule part
 * > specified in a RECUR value.
 *
 * So reading takes the parts in any order and says nothing about it, and
 * writing always puts `FREQ` first. **The `BYxxx` parts are then written in
 * the order §3.3.10 evaluates them** — BYMONTH, BYWEEKNO, BYYEARDAY,
 * BYMONTHDAY, BYDAY, BYHOUR, BYMINUTE, BYSECOND, BYSETPOS. Any order would
 * do; using the one the memo already names means there is no second order to
 * remember.
 *
 * ## A RECUR value is case-sensitive
 *
 * ABNF's own default is the other way about — a quoted literal in RFC 5234
 * matches either case — but RFC 5545 overrides it twice, in §2 and again in
 * §3.1, in the same words: "All names of properties, property parameters,
 * enumerated property values and property parameter values are
 * case-insensitive. **However, all other property values are case-sensitive,
 * unless otherwise stated.**"
 *
 * A `RECUR` value is a property value and none of those four kinds, and
 * §3.3.10 states nothing otherwise. So `FREQ=DAILY` is a rule and
 * `freq=daily` is not.
 *
 * ## What is written back is the rule, not the spelling
 *
 * A part that says what the default says is left out, because `INTERVAL=1`
 * and `WKST=MO` are exactly the absence of those parts, and `+1MO` is written
 * `1MO` because §3.3.10 says in as many words that they are the same thing.
 * {@see \DavServices\VObject\Value\Duration} set the precedent by dropping
 * the optional `+`: a value type is for callers who mean to change something,
 * and normalising what carries no meaning is not the same as changing what
 * does.
 *
 * ## What is not judged here, and why
 *
 * **Three of §3.3.10's sentences need the `DTSTART` this rule was written
 * beside**, which a value has no way to see:
 *
 * - "The BYSECOND, BYMINUTE and BYHOUR rule parts MUST NOT be specified when
 *   the associated 'DTSTART' property has a DATE value type."
 * - "The value of the UNTIL rule part MUST have the same value type as the
 *   'DTSTART' property."
 * - and the sentences that follow it about local time and UTC.
 *
 * **The last of those contradicts itself**, which is worth writing down
 * rather than quietly picking a side. §3.3.10 says "if the 'DTSTART' property
 * is specified as a date with local time, then the UNTIL rule part MUST also
 * be specified as a date with local time", and four sentences later "If
 * specified as a DATE-TIME value, then it MUST be specified in a UTC time
 * format". Both cannot hold for a floating `DTSTART`. So `UNTIL` is read as
 * whichever of `date` and `date-time` it is, and which of them it ought to
 * have been is left to whoever holds the component.
 */
final class Rule
{
    /**
     * `recur-rule-part` is `NAME "=" value` throughout, and the name is
     * matched in upper case only — see the note on case above.
     */
    private const PART = '/^([A-Z]+)=(.*)$/';

    /**
     * Every `recur-rule-part` the grammar has, and nothing else is one.
     */
    private const PARTS = [
        'FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR',
        'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST',
    ];

    private const DIGITS = '/^[0-9]+$/';

    /**
     * `1*2DIGIT`, unsigned: `seconds`, `minutes`, `hour`, `monthnum`.
     */
    private const SMALL = '/^[0-9]{1,2}$/';

    /**
     * `[plus / minus] 1*2DIGIT`: `monthdaynum`, `weeknum`.
     */
    private const SIGNED_SMALL = '/^[+-]?[0-9]{1,2}$/';

    /**
     * `[plus / minus] 1*3DIGIT`: `yeardaynum`, and `setposday` which is one.
     */
    private const SIGNED_LARGE = '/^[+-]?[0-9]{1,3}$/';

    /**
     * @param list<int> $bySecond
     * @param list<int> $byMinute
     * @param list<int> $byHour
     * @param list<WeekdayNumber> $byDay
     * @param list<int> $byMonthDay
     * @param list<int> $byYearDay
     * @param list<int> $byWeekNumber
     * @param list<int> $byMonth
     * @param list<int> $bySetPosition
     */
    private function __construct(
        private readonly Frequency $frequency,
        private readonly int $interval,
        private readonly ?int $count,
        private readonly Date|DateTime|null $until,
        private readonly Weekday $weekStart,
        private readonly array $bySecond,
        private readonly array $byMinute,
        private readonly array $byHour,
        private readonly array $byDay,
        private readonly array $byMonthDay,
        private readonly array $byYearDay,
        private readonly array $byWeekNumber,
        private readonly array $byMonth,
        private readonly array $bySetPosition,
    ) {
    }

    /**
     * The rule a raw value names.
     *
     * @throws ParseError If it is no `recur` by the grammar, or if it puts
     *                    together rule parts §3.3.10 keeps apart
     */
    public static function decode(string $raw): self
    {
        $parts = self::partsIn($raw);

        $rule = new self(
            self::frequencyIn($parts),
            self::intervalIn($parts),
            self::countIn($parts),
            self::untilIn($parts),
            self::weekStartIn($parts),
            self::numbersIn($parts, 'BYSECOND', self::SMALL, 0, 60),
            self::numbersIn($parts, 'BYMINUTE', self::SMALL, 0, 59),
            self::numbersIn($parts, 'BYHOUR', self::SMALL, 0, 23),
            self::daysIn($parts),
            self::numbersIn($parts, 'BYMONTHDAY', self::SIGNED_SMALL, 1, 31),
            self::numbersIn($parts, 'BYYEARDAY', self::SIGNED_LARGE, 1, 366),
            self::numbersIn($parts, 'BYWEEKNO', self::SIGNED_SMALL, 1, 53),
            self::numbersIn($parts, 'BYMONTH', self::SMALL, 1, 12),
            self::numbersIn($parts, 'BYSETPOS', self::SIGNED_LARGE, 1, 366),
        );

        $rule->refuseWhatMayNotStandTogether();

        return $rule;
    }

    /**
     * How often it repeats. The one rule part that must be there.
     */
    public function frequency(): Frequency
    {
        return $this->frequency;
    }

    /**
     * At what intervals it repeats: "The default value is '1'."
     */
    public function interval(): int
    {
        return $this->interval;
    }

    /**
     * How many occurrences bound it, or null where nothing does.
     *
     * "The 'DTSTART' property value always counts as the first occurrence."
     */
    public function count(): ?int
    {
        return $this->count;
    }

    /**
     * The date or instant that bounds it "in an inclusive manner", or null.
     *
     * Null from both this and {@see self::count()} is what §3.3.10 calls
     * repeating forever.
     */
    public function until(): Date|DateTime|null
    {
        return $this->until;
    }

    /**
     * The day the week starts on: "The default value is MO."
     *
     * It matters in two places and nowhere else — "when a WEEKLY 'RRULE' has
     * an interval greater than 1, and a BYDAY rule part is specified", and
     * "in a YEARLY 'RRULE' when a BYWEEKNO rule part is specified".
     */
    public function weekStart(): Weekday
    {
        return $this->weekStart;
    }

    /**
     * Seconds within a minute: "Valid values are 0 to 60."
     *
     * Sixty is not a slip. That is what a leap second is called.
     *
     * @return list<int>
     */
    public function bySecond(): array
    {
        return $this->bySecond;
    }

    /**
     * Minutes within an hour: "Valid values are 0 to 59."
     *
     * @return list<int>
     */
    public function byMinute(): array
    {
        return $this->byMinute;
    }

    /**
     * Hours of the day: "Valid values are 0 to 23."
     *
     * @return list<int>
     */
    public function byHour(): array
    {
        return $this->byHour;
    }

    /**
     * Days of the week, each with or without an ordinal.
     *
     * @return list<WeekdayNumber>
     */
    public function byDay(): array
    {
        return $this->byDay;
    }

    /**
     * Days of the month: "Valid values are 1 to 31 or -31 to -1. For example,
     * -10 represents the tenth to the last day of the month."
     *
     * @return list<int>
     */
    public function byMonthDay(): array
    {
        return $this->byMonthDay;
    }

    /**
     * Days of the year: "Valid values are 1 to 366 or -366 to -1."
     *
     * @return list<int>
     */
    public function byYearDay(): array
    {
        return $this->byYearDay;
    }

    /**
     * Weeks of the year, numbered as [ISO.8601.2004] numbers them: "Valid
     * values are 1 to 53 or -53 to -1."
     *
     * @return list<int>
     */
    public function byWeekNumber(): array
    {
        return $this->byWeekNumber;
    }

    /**
     * Months of the year: "Valid values are 1 to 12."
     *
     * @return list<int>
     */
    public function byMonth(): array
    {
        return $this->byMonth;
    }

    /**
     * Which occurrences of the set one interval produces: "Valid values are 1
     * to 366 or -366 to -1."
     *
     * @return list<int>
     */
    public function bySetPosition(): array
    {
        return $this->bySetPosition;
    }

    /**
     * As it is written in a file, with `FREQ` first.
     */
    public function encode(): string
    {
        $written = 'FREQ=' . $this->frequency->value
            . $this->intervalWritten()
            . $this->countWritten()
            . $this->untilWritten()
            . $this->weekStartWritten();

        foreach ($this->lists() as $name => $values) {
            if ($values !== []) {
                $written .= ';' . $name . '=' . implode(',', $values);
            }
        }

        return $written;
    }

    /**
     * "The default value is '1'", and a part that says the default says
     * nothing the absence of the part does not.
     */
    private function intervalWritten(): string
    {
        return $this->interval === 1 ? '' : ';INTERVAL=' . $this->interval;
    }

    /**
     * A count of nought is written, unlike an absent one: nought is a bound
     * somebody put there.
     */
    private function countWritten(): string
    {
        return $this->count === null ? '' : ';COUNT=' . $this->count;
    }

    private function untilWritten(): string
    {
        return $this->until === null ? '' : ';UNTIL=' . $this->until->encode();
    }

    /**
     * "The default value is MO" — the same as the interval, for the same
     * reason.
     */
    private function weekStartWritten(): string
    {
        return $this->weekStart === Weekday::Monday ? '' : ';WKST=' . $this->weekStart->value;
    }

    /**
     * The rule parts, each once, by name.
     *
     *
     * @throws ParseError If a part is no `NAME=VALUE`, names nothing the
     *                    grammar has, or is specified twice
     *
     * @return array<string, string>
     */
    private static function partsIn(string $raw): array
    {
        $parts = [];

        foreach (explode(';', $raw) as $part) {
            if (preg_match(self::PART, $part, $named) !== 1) {
                throw new ParseError(sprintf('"%s" is no rule part: RFC 5545 §3.3.10 has NAME=VALUE.', $part));
            }

            if (!in_array($named[1], self::PARTS, true)) {
                throw new ParseError(sprintf('"%s" is no rule part of RFC 5545 §3.3.10.', $named[1]));
            }

            // "Individual rule parts MUST only be specified once."
            if (isset($parts[$named[1]])) {
                throw new ParseError(sprintf('"%s" is specified twice, and a rule part is specified once.', $named[1]));
            }

            $parts[$named[1]] = $named[2];
        }

        return $parts;
    }

    /**
     * @param array<string, string> $parts
     *
     * @throws ParseError If it is missing, which it may not be, or names no
     *                    frequency this memo has
     */
    private static function frequencyIn(array $parts): Frequency
    {
        $raw = $parts['FREQ'] ?? null;

        if ($raw === null) {
            throw new ParseError('A recurrence rule has a FREQ: RFC 5545 §3.3.10 makes it REQUIRED.');
        }

        return Frequency::tryFrom($raw)
            ?? throw new ParseError(sprintf('"%s" is no frequency of RFC 5545 §3.3.10.', $raw));
    }

    /**
     * "The INTERVAL rule part contains a positive integer […] The default
     * value is '1'."
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If it is no `1*DIGIT`, or is not positive
     */
    private static function intervalIn(array $parts): int
    {
        $raw = $parts['INTERVAL'] ?? null;

        if ($raw === null) {
            return 1;
        }

        if (preg_match(self::DIGITS, $raw) !== 1) {
            throw new ParseError(sprintf('"%s" is no INTERVAL: RFC 5545 §3.3.10 has 1*DIGIT.', $raw));
        }

        $interval = (int) $raw;

        if ($interval < 1) {
            throw new ParseError(sprintf('An INTERVAL is a positive integer (RFC 5545 §3.3.10), not "%s".', $raw));
        }

        return $interval;
    }

    /**
     * **Nought is accepted**, unlike an interval of nought, and the
     * difference is in the wording: §3.3.10 says an INTERVAL "contains a
     * positive integer" and says no such thing of a COUNT, whose grammar is
     * `1*DIGIT` and nothing more. What a bound of nought then means belongs
     * to whatever expands the rule.
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If it is no `1*DIGIT`
     */
    private static function countIn(array $parts): ?int
    {
        $raw = $parts['COUNT'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (preg_match(self::DIGITS, $raw) !== 1) {
            throw new ParseError(sprintf('"%s" is no COUNT: RFC 5545 §3.3.10 has 1*DIGIT.', $raw));
        }

        return (int) $raw;
    }

    /**
     * `enddate = date / date-time`, told apart by the `T` that every
     * `date-time` has and no `date` may.
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If it is neither
     */
    private static function untilIn(array $parts): Date|DateTime|null
    {
        $raw = $parts['UNTIL'] ?? null;

        if ($raw === null) {
            return null;
        }

        return str_contains($raw, 'T') ? DateTime::decode($raw) : Date::decode($raw);
    }

    /**
     * `WKST` takes a bare `weekday`, so `WKST=1MO` is no rule: the ordinal
     * belongs to `weekdaynum`, which only `BYDAY` is made of.
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If it names no day of the week
     */
    private static function weekStartIn(array $parts): Weekday
    {
        $raw = $parts['WKST'] ?? null;

        if ($raw === null) {
            return Weekday::Monday;
        }

        return Weekday::tryFrom($raw)
            ?? throw new ParseError(sprintf('"%s" is no day of the week (RFC 5545 §3.3.10).', $raw));
    }

    /**
     * One COMMA-separated list of numbers, against its own grammar and its
     * own range.
     *
     * **The range is checked on the size rather than the number**, because
     * every signed list in §3.3.10 gives its range twice and symmetrically —
     * "1 to 366 or -366 to -1" — and an unsigned one can hold nothing
     * negative to begin with.
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If a value is no number of that shape, or outside
     *                    the range the grammar's own comment gives
     *
     * @return list<int>
     */
    private static function numbersIn(array $parts, string $name, string $grammar, int $least, int $most): array
    {
        $raw = $parts[$name] ?? null;

        if ($raw === null) {
            return [];
        }

        $numbers = [];

        foreach (self::listIn($raw, $name) as $value) {
            if (preg_match($grammar, $value) !== 1) {
                throw new ParseError(sprintf('"%s" is no value of a %s list.', $value, $name));
            }

            $number = (int) $value;

            if (abs($number) < $least || abs($number) > $most) {
                throw new ParseError(
                    sprintf('"%s" is outside %s, which RFC 5545 §3.3.10 gives as %d to %d.', $value, $name, $least, $most),
                );
            }

            $numbers[] = $number;
        }

        return $numbers;
    }

    /**
     * `bywdaylist = ( weekdaynum *("," weekdaynum) )`.
     *
     * @param array<string, string> $parts
     *
     * @throws ParseError If an entry is no `weekdaynum`
     *
     * @return list<WeekdayNumber>
     */
    private static function daysIn(array $parts): array
    {
        $raw = $parts['BYDAY'] ?? null;

        if ($raw === null) {
            return [];
        }

        $days = [];

        foreach (self::listIn($raw, 'BYDAY') as $value) {
            $days[] = WeekdayNumber::decode($value);
        }

        return $days;
    }

    /**
     * The values of a COMMA-separated list.
     *
     * Every list in §3.3.10 is `( value *("," value) )` — one value at least,
     * and a value between every pair of commas. So an empty list and a hole
     * in one are both no list at all.
     *
     *
     * @throws ParseError If any value is missing
     *
     * @return list<string>
     */
    private static function listIn(string $raw, string $name): array
    {
        $values = explode(',', $raw);

        foreach ($values as $value) {
            if ($value === '') {
                throw new ParseError(sprintf('%s has an empty value in "%s".', $name, $raw));
            }
        }

        return $values;
    }

    /**
     * Refuses a rule that puts together what §3.3.10 keeps apart.
     *
     * **Six sentences, six predicates, one throw.** Each sentence is quoted
     * where the condition for it is written, and what is wrong is named
     * rather than spelled out again at the point of refusal — six copies of
     * `if (…) { throw … }` said the same thing six times and hid which of the
     * six a reader was looking at.
     *
     * @throws ParseError If any of the six is broken
     */
    private function refuseWhatMayNotStandTogether(): void
    {
        foreach ($this->whatWouldBeWrong() as [$wrong, $because]) {
            if ($wrong) {
                throw new ParseError($because);
            }
        }
    }

    /**
     * What would be wrong with this rule, and what to say about it.
     *
     * @return list<array{bool, string}>
     */
    private function whatWouldBeWrong(): array
    {
        return [
            [
                $this->boundTwice(),
                'UNTIL and COUNT MUST NOT occur in the same recurrence rule (RFC 5545 §3.3.10).',
            ],
            [
                $this->ordinalDayWithNothingToCountIn(),
                'A BYDAY with a number needs a MONTHLY rule, or a YEARLY one with no BYWEEKNO (RFC 5545 §3.3.10).',
            ],
            [
                $this->byMonthDay !== [] && $this->frequency === Frequency::Weekly,
                'BYMONTHDAY MUST NOT be specified in a WEEKLY rule (RFC 5545 §3.3.10).',
            ],
            [
                $this->byYearDay !== [] && $this->isShorterThanAYear(),
                'BYYEARDAY MUST NOT be specified in a DAILY, WEEKLY or MONTHLY rule (RFC 5545 §3.3.10).',
            ],
            [
                $this->byWeekNumber !== [] && $this->frequency !== Frequency::Yearly,
                'BYWEEKNO MUST NOT be used outside a YEARLY rule (RFC 5545 §3.3.10).',
            ],
            [
                $this->bySetPosition !== [] && !$this->hasAnotherBy(),
                'BYSETPOS needs another BYxxx rule part to select from (RFC 5545 §3.3.10).',
            ],
        ];
    }

    /**
     * "The UNTIL or COUNT rule parts are OPTIONAL, but they MUST NOT occur in
     * the same 'recur'."
     */
    private function boundTwice(): bool
    {
        return $this->count !== null && $this->until !== null;
    }

    /**
     * "The BYDAY rule part MUST NOT be specified with a numeric value when
     * the FREQ rule part is not set to MONTHLY or YEARLY. Furthermore, the
     * BYDAY rule part MUST NOT be specified with a numeric value with the
     * FREQ rule part set to YEARLY when the BYWEEKNO rule part is specified."
     */
    private function ordinalDayWithNothingToCountIn(): bool
    {
        return $this->anyDayCarriesAnOrdinal() && !$this->takesAnOrdinalDay();
    }

    /**
     * The three frequencies "The BYYEARDAY rule part MUST NOT be specified"
     * in: "DAILY, WEEKLY, or MONTHLY".
     */
    private function isShorterThanAYear(): bool
    {
        return in_array(
            $this->frequency,
            [Frequency::Daily, Frequency::Weekly, Frequency::Monthly],
            true,
        );
    }

    /**
     * Whether any entry of `BYDAY` names which one of that day it wants.
     */
    private function anyDayCarriesAnOrdinal(): bool
    {
        foreach ($this->byDay as $day) {
            if ($day->ordinal() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an ordinal on a `BYDAY` has anything to count within.
     */
    private function takesAnOrdinalDay(): bool
    {
        if ($this->frequency === Frequency::Monthly) {
            return true;
        }

        return $this->frequency === Frequency::Yearly && $this->byWeekNumber === [];
    }

    /**
     * Whether anything else selects a set for `BYSETPOS` to count through.
     */
    private function hasAnotherBy(): bool
    {
        foreach ($this->lists() as $name => $values) {
            if ($name !== 'BYSETPOS' && $values !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `BYxxx` parts as they are written, in the order §3.3.10 evaluates
     * them: "BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY, BYHOUR,
     * BYMINUTE, BYSECOND and BYSETPOS".
     *
     * @return array<string, list<string>>
     */
    private function lists(): array
    {
        $days = [];

        foreach ($this->byDay as $day) {
            $days[] = $day->encode();
        }

        return [
            'BYMONTH' => self::asText($this->byMonth),
            'BYWEEKNO' => self::asText($this->byWeekNumber),
            'BYYEARDAY' => self::asText($this->byYearDay),
            'BYMONTHDAY' => self::asText($this->byMonthDay),
            'BYDAY' => $days,
            'BYHOUR' => self::asText($this->byHour),
            'BYMINUTE' => self::asText($this->byMinute),
            'BYSECOND' => self::asText($this->bySecond),
            'BYSETPOS' => self::asText($this->bySetPosition),
        ];
    }

    /**
     * @param list<int> $values
     *
     * @return list<string>
     */
    private static function asText(array $values): array
    {
        $text = [];

        foreach ($values as $value) {
            $text[] = (string) $value;
        }

        return $text;
    }
}
