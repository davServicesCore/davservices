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

namespace DavServices\Tests\Unit\VObject\Value;

use DavServices\VObject\ParseError;
use DavServices\VObject\Value\CalAddress;
use DavServices\VObject\Value\UtcOffset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `UTC-OFFSET` and `CAL-ADDRESS`, derived from RFC 5545 §3.3.14
 * and §3.3.3.
 *
 *     utc-offset   = time-numzone
 *     time-numzone = ("+" / "-") time-hour time-minute [time-second]
 *
 * §3.3.14 carries three rules that are easy to read past and each of which a
 * client has got wrong somewhere:
 *
 * - **"The PLUS SIGN character MUST be specified for positive UTC offsets"**
 *   — so `0100` without a sign is not an offset.
 * - **"The value of '-0000' and '-000000' are not allowed."** A negative zero
 *   says "no offset known", which is a different thing from UTC, and this
 *   format has no room for it.
 * - **"The time-second, if present, MUST NOT be 60"** — a leap second is a
 *   moment, not a distance.
 *
 * `CAL-ADDRESS` is `cal-address = uri` and carries its value through
 * untouched, the same as {@see \DavServices\VObject\Value\Uri} and for the
 * same reason: "No additional content value encoding … is defined for this
 * value type."
 */
#[CoversClass(UtcOffset::class)]
#[CoversClass(CalAddress::class)]
final class UtcOffsetTest extends TestCase
{
    /**
     * §3.3.14's own examples: "standard time for New York (five hours behind
     * UTC) and Geneva (one hour ahead of UTC)".
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theExamplesFromTheSpecification')]
    public function testReadsTheExamples(string $raw, int $seconds): void
    {
        self::assertSame($seconds, UtcOffset::decode($raw)->seconds());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theExamplesFromTheSpecification(): iterable
    {
        yield 'New York' => ['-0500', -18000];

        yield 'Geneva' => ['+0100', 3600];

        yield 'UTC itself' => ['+0000', 0];
    }

    /**
     * **Offsets that are not whole hours.** India stands at `+0530`, Nepal at
     * `+0545` and Newfoundland at `-0330` — so the minutes are not decoration,
     * and an implementation that dropped or mis-scaled them would put a third
     * of the world's clocks out by half an hour.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('offsetsThatAreNotWholeHours')]
    public function testTheMinutesCount(string $raw, int $seconds): void
    {
        self::assertSame($seconds, UtcOffset::decode($raw)->seconds());
        self::assertSame($raw, UtcOffset::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function offsetsThatAreNotWholeHours(): iterable
    {
        yield 'India' => ['+0530', 19800];

        yield 'Nepal' => ['+0545', 20700];

        yield 'Newfoundland' => ['-0330', -12600];

        yield 'the largest minute there is' => ['+0159', 7140];

        yield 'and the largest second' => ['+010059', 3659];

        yield 'one second short of an hour, where the hour division decides' => ['+005959', 3599];
    }

    /**
     * **The seconds are optional and default to zero** — "The time-second, if
     * present, MUST NOT be 60; if absent, it defaults to zero."
     */
    public function testTheSecondsAreOptional(): void
    {
        self::assertSame(-18000, UtcOffset::decode('-050000')->seconds());
        self::assertSame(-18030, UtcOffset::decode('-050030')->seconds());
    }

    /**
     * **What §3.3.14 forbids is refused**, each rule with a case of its own.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoOffset')]
    public function testWhatIsNoOffsetIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        UtcOffset::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoOffset(): iterable
    {
        yield 'no sign, which the plus rule forbids' => ['0100'];

        yield 'negative zero, which §3.3.14 names' => ['-0000'];

        yield 'and its long form, which it names too' => ['-000000'];

        yield 'a leap second, which a distance cannot have' => ['+010060'];

        yield 'a minute of sixty' => ['+016000'];

        yield 'too few digits' => ['+01'];

        yield 'too many' => ['+0100000'];

        yield 'separators' => ['+01:00'];

        yield 'nothing at all' => [''];

        yield 'something in front of it' => ['x+0100'];
    }

    /**
     * **Positive zero is allowed**, and only the negative one is not: `+0000`
     * is UTC said out loud, which a `VTIMEZONE` for Greenwich has to be able
     * to say.
     */
    public function testPositiveZeroIsAllowed(): void
    {
        self::assertSame(0, UtcOffset::decode('+0000')->seconds());
    }

    /**
     * Written back with the sign the rule requires, and with the seconds only
     * where there are any: §3.3.14 makes them optional and has them default
     * to zero, so the shorter form says the same thing.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsWrittenBack')]
    public function testIsWrittenBackInTheCanonicalForm(string $raw, string $expected): void
    {
        self::assertSame($expected, UtcOffset::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatIsWrittenBack(): iterable
    {
        yield 'a whole hour' => ['+0100', '+0100'];

        yield 'behind UTC' => ['-0500', '-0500'];

        yield 'zero seconds are left off' => ['-050000', '-0500'];

        yield 'and kept where there are some' => ['-050030', '-050030'];

        yield 'UTC itself' => ['+0000', '+0000'];
    }

    /**
     * §3.3.3: `cal-address = uri`. "When used to address an Internet email
     * transport address for a calendar user, the value MUST be a mailto URI",
     * which is what every `ATTENDEE` in practice is.
     */
    public function testACalendarUserAddressIsCarriedThroughUntouched(): void
    {
        $raw = 'mailto:jane_doe@example.com';

        self::assertSame($raw, CalAddress::decode($raw));
        self::assertSame($raw, CalAddress::encode($raw));
    }

    /**
     * And nothing in it is escaped, which matters because an address may
     * carry the characters `TEXT` would escape.
     */
    public function testNothingInAnAddressIsEscaped(): void
    {
        $raw = 'mailto:ada@example.com?subject=a,b;c';

        self::assertSame($raw, CalAddress::encode($raw));
    }
}
