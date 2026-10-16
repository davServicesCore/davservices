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

namespace DavServices\Tests\Unit\Fuzz;

use DavServices\Tests\Fuzz\Run;
use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\TooManyIterations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Test list for one run of the fuzzer over one input. R-QS-19, P4-14.
 *
 * > R-QS-19: Fuzzing für den iCalendar- und vCard-Parser sowie für die
 * > Bildverarbeitung.
 *
 * **What a fuzzer is here is a question about what counts as a finding**, and
 * that question is the part worth testing. The plan names three answers:
 * no endless loop, no memory error, and **only defined exceptions** — so a
 * run over one input has to tell those apart from a parser that is simply
 * refusing malformed data, which it does all day and on purpose.
 *
 * ## What may happen, and what may not
 *
 * | the parser | verdict |
 * |---|---|
 * | returns something | fine |
 * | throws `ParseError` | fine — that is a refusal, and §3.1 of both memos is full of reasons for one |
 * | throws `TooManyIterations` | fine — R-RRULE-04's hard limit saying no |
 * | throws anything else | **a finding**: a `TypeError` is a bug, not a refusal |
 * | raises a warning, notice or deprecation | **a finding** |
 * | runs past its time limit | **a finding**, which is how an endless loop shows up |
 *
 * **The input goes into the finding**, shortened but reproducible, because a
 * fuzzer that says only "something broke" costs more time than it saves.
 */
#[CoversClass(Run::class)]
final class RunTest extends TestCase
{
    /**
     * **A run that comes back with something is no finding.**
     */
    public function testARunThatComesBackIsNoFinding(): void
    {
        self::assertNull(Run::of('BEGIN:VCARD', static fn (): string => 'anything'));
    }

    /**
     * **A refusal is no finding either**, and this is the whole reason the
     * list exists: both parsers refuse malformed input by design, so a fuzzer
     * that counted refusals would report thousands of them and find nothing.
     *
     * @param callable(): never $refuse
     */
    #[DataProvider('theExceptionsAParserMayThrow')]
    public function testARefusalIsNoFinding(callable $refuse): void
    {
        self::assertNull(Run::of('rubbish', $refuse));
    }

    /**
     * The two the library defines for this: {@see ParseError} for "this is not
     * what the grammar says" and {@see TooManyIterations} for R-RRULE-04's
     * limit.
     *
     * **Each one is thrown the way the library throws it**, which is why this
     * hands over a thrower rather than a class name: `TooManyIterations` takes
     * the limit it passed, not a message, and a list that built both from one
     * string would be testing a constructor nobody has.
     *
     * @return iterable<string, array{callable(): never}>
     */
    public static function theExceptionsAParserMayThrow(): iterable
    {
        yield 'a refusal' => [static function (): never {
            throw new ParseError('this line has no colon in it');
        }];

        yield 'the hard limit' => [static function (): never {
            throw new TooManyIterations(10_000);
        }];
    }

    /**
     * **Any other throwable is a finding**, and the class is named in it: a
     * `TypeError` from inside a parser means the parser believed something
     * about its input that was not true, which is a defect and not a refusal.
     */
    public function testAnyOtherThrowableIsAFinding(): void
    {
        $found = Run::of('BEGIN:VCARD', static function (): never {
            throw new TypeError('argument 1 must be string');
        });

        self::assertNotNull($found);
        self::assertStringContainsString('TypeError', $found);
        self::assertStringContainsString('argument 1 must be string', $found);
    }

    /**
     * **A warning is a finding too.** The suite runs with `failOnWarning`, so
     * a parser that reads past the end of a string fails a unit test — and
     * the fuzzer has to hold the same line, or the inputs nobody wrote a test
     * for would get a quieter treatment than the ones somebody did.
     */
    public function testAWarningIsAFinding(): void
    {
        $found = Run::of('BEGIN:VCARD', static function (): string {
            // A deliberate notice: reading an offset that is not there.
            $empty = [];

            /** @phpstan-ignore-next-line offsetAccess.notFound */
            return (string) ($empty[1] ?? trigger_error('something slipped', E_USER_WARNING));
        });

        self::assertNotNull($found);
        self::assertStringContainsString('something slipped', $found);
    }

    /**
     * **A suppressed diagnostic is a finding too**, which is the opposite of
     * what I first wrote here and the measurement decided it. The usual guard
     * reads `error_reporting()` to tell an `@` apart from an ordinary
     * diagnostic — and inside a PHPUnit test `error_reporting()` is 245, a
     * mask PHPUnit picks for itself that leaves out E_WARNING, E_NOTICE,
     * E_DEPRECATED and every E_USER_*. The guard would have discarded
     * precisely what the fuzzer is for, and only under the suite, where it
     * would have been hardest to notice.
     */
    public function testASuppressedDiagnosticIsAFindingToo(): void
    {
        $found = Run::of('BEGIN:VCARD', static fn (): string => (string) @file_get_contents(
            __DIR__ . '/a file that is not there',
        ));

        self::assertNotNull($found);
        self::assertStringContainsString('No such file or directory', $found);
    }

    /**
     * **A run that outlasts its limit is a finding**, which is how an endless
     * loop announces itself: the plan asks for "keine Endlosschleife", and a
     * loop that never ends cannot be caught by waiting for it to end.
     */
    public function testARunThatOutlastsItsLimitIsAFinding(): void
    {
        $found = Run::of('BEGIN:VCARD', static function (): string {
            usleep(30_000);

            return 'slow';
        }, 0.001);

        self::assertNotNull($found);
        self::assertStringContainsString('0.0', $found);
    }

    /**
     * **A reading over the limit is taken again before it counts**, and the
     * clock is why: `hrtime` reads a wall clock, and the suite runs its
     * coverage in ten instrumented processes at once. A parse that takes five
     * milliseconds on a quiet machine was measured at 2.3 seconds on that one
     * — a finding about the scheduler, which is the kind of finding that
     * teaches everybody to ignore findings.
     *
     * **A stuck parser cannot be read twice**, because it never comes back
     * from the first reading. So a second reading inside the limit is proof
     * that the first was the machine, while a parse that is genuinely slow is
     * slow both times and stays a finding
     * ({@see testARunThatOutlastsItsLimitIsAFinding}).
     */
    public function testAReadingTheMachineStretchedIsNotAFinding(): void
    {
        $readings = 0;

        $found = Run::of('BEGIN:VCARD', static function () use (&$readings): string {
            if (++$readings === 1) {
                usleep(30_000);
            }

            return 'quick the second time';
        }, 0.001);

        self::assertNull($found);
        self::assertSame(2, $readings);
    }
    /**
     * **And the input is in the finding**, shortened so that a long one does
     * not bury the report, with its length said so that nothing looks
     * complete when it is not.
     */
    public function testTheInputIsInTheFinding(): void
    {
        $long = 'BEGIN:VCARD' . str_repeat('x', 500);
        $found = Run::of($long, static function (): never {
            throw new TypeError('broken');
        });

        self::assertNotNull($found);
        self::assertStringContainsString('BEGIN:VCARD', $found);
        self::assertStringContainsString('511 bytes', $found);
        self::assertLessThan(400, strlen($found));
    }

    /**
     * **An input that is not printable is written as bytes**, a fuzzer's
     * inputs being full of them: a finding that pasted a raw control
     * character into a terminal would be a finding nobody can read.
     */
    public function testAnInputThatIsNotPrintableIsWrittenAsBytes(): void
    {
        $found = Run::of("BEGIN:\x00\x1bVCARD", static function (): never {
            throw new TypeError('broken');
        });

        self::assertNotNull($found);
        self::assertStringContainsString('\x00', $found);
        self::assertStringContainsString('\x1b', $found);
    }
}
