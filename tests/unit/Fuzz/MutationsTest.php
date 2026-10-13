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

use DavServices\Tests\Fuzz\Mutations;
use DavServices\VObject\Component;
use DavServices\VObject\Finding;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the inputs the fuzzer makes. R-QS-19, P4-14.
 *
 * **A fuzzer is only as good as its inputs**, and random bytes are the weakest
 * kind: a parser refuses them at the first character and nothing deeper is
 * ever reached. So these inputs start from objects that are **valid** and
 * break them in named ways — a byte flipped, a line cut, a character halved
 * through the middle of its UTF-8 sequence — which is how a mutation reaches
 * the code that runs after the first line was understood.
 *
 * Three of the five objects come from the memos and two do not, and
 * {@see Mutations::seeds()} says which is which. **That distinction is kept
 * rather than glossed**: vCard 2.1 has no RFC to quote, so a seed for it is
 * built from what the converter reads, and saying "published" of it would be
 * the kind of claim this project does not make.
 *
 * ## Every mutation owes three things
 *
 * - **it changes the input**, or it tests nothing;
 * - **the same seed gives the same input**, or a finding cannot be
 *   reproduced — which is the whole value of a finding;
 * - **different seeds give different inputs**, or the run is one input tried
 *   a thousand times.
 */
#[CoversClass(Mutations::class)]
final class MutationsTest extends TestCase
{
    /**
     * **The objects the mutations start from are valid**, which is what makes
     * a mutation of them interesting: they come from the memos themselves, so
     * the parser gets past its first refusal and into the code underneath.
     *
     * **And the validator has nothing to say about any of them**, which is
     * the stronger half of it: every finding a later run reports therefore
     * belongs to the mutation rather than to the seed it was made from. A
     * single sloppy seed would otherwise put the same finding under every one
     * of a million runs.
     */
    public function testTheObjectsTheMutationsStartFromAreValid(): void
    {
        $seeds = Mutations::seeds();
        $validator = new Validator();

        self::assertGreaterThan(2, count($seeds));

        foreach ($seeds as $name => $text) {
            $objects = iterator_to_array((new Reader($text))->objects(), false);
            $read = array_map(static fn (Component $object): string => $object->name(), $objects);

            self::assertCount(1, $read, $name);
            self::assertSame([], array_diff($read, ['VCARD', 'VCALENDAR']), $name);

            foreach ($objects as $object) {
                $findings = array_map(
                    static fn (Finding $finding): string => $finding->message(),
                    $validator->check($object),
                );

                self::assertSame([], $findings, $name);
            }
        }
    }

    /**
     * **Every mutation changes what it was given.**
     *
     * @param non-empty-string $mutation
     */
    #[DataProvider('everyMutation')]
    public function testEveryMutationChangesWhatItWasGiven(string $mutation): void
    {
        $before = Mutations::seeds()['a calendar'] ?? '';
        $changed = 0;

        // A few seeds, because one mutation of one input can land on a
        // character that happens to be the same again.
        foreach (range(1, 20) as $seed) {
            if (Mutations::apply($mutation, $before, $seed) !== $before) {
                ++$changed;
            }
        }

        self::assertGreaterThan(15, $changed, $mutation);
    }

    /**
     * **The same seed gives the same input**, without which a finding cannot
     * be looked at twice.
     *
     * @param non-empty-string $mutation
     */
    #[DataProvider('everyMutation')]
    public function testTheSameSeedGivesTheSameInput(string $mutation): void
    {
        $text = Mutations::seeds()['a card'] ?? '';

        self::assertSame(
            Mutations::apply($mutation, $text, 7),
            Mutations::apply($mutation, $text, 7),
            $mutation,
        );
    }

    /**
     * **Different seeds give different inputs**, or a thousand runs would be
     * one run done a thousand times.
     *
     * @param non-empty-string $mutation
     */
    #[DataProvider('everyMutation')]
    public function testDifferentSeedsGiveDifferentInputs(string $mutation): void
    {
        $text = Mutations::seeds()['a card'] ?? '';
        $seen = [];

        foreach (range(1, 30) as $seed) {
            $seen[Mutations::apply($mutation, $text, $seed)] = true;
        }

        self::assertGreaterThan(3, count($seen), $mutation);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyMutation(): iterable
    {
        foreach (Mutations::names() as $name) {
            yield $name => [$name];
        }
    }

    /**
     * **A flipped byte is one byte apart**, which is the mutation that finds
     * what a reader does with a character it did not expect in a place it
     * knows.
     */
    public function testAFlippedByteIsOneByteApart(): void
    {
        $text = Mutations::seeds()['a card'] ?? '';
        $flipped = Mutations::apply('a flipped byte', $text, 3);

        self::assertSame(strlen($text), strlen($flipped));
        self::assertSame(1, self::bytesApart($text, $flipped));
    }

    /**
     * **A cut input is shorter and stays a prefix**, which is what a reader
     * meets when a connection drops: everything it has read so far was
     * correct, and then nothing.
     */
    public function testACutInputIsShorterAndStaysAPrefix(): void
    {
        $text = Mutations::seeds()['a card'] ?? '';
        $cut = Mutations::apply('a cut', $text, 3);

        self::assertLessThan(strlen($text), strlen($cut));
        self::assertSame($cut, substr($text, 0, strlen($cut)));
    }

    /**
     * **A halved character leaves an incomplete UTF-8 sequence**, which the
     * memos make a real case rather than an exotic one: RFC 6350 §3.1 makes
     * UTF-8 the only charset of a vCard, so a reader meets its broken forms.
     */
    public function testAHalvedCharacterLeavesAnIncompleteSequence(): void
    {
        $halved = Mutations::apply('a halved character', "BEGIN:VCARD\r\nFN:Grüße\r\nEND:VCARD\r\n", 1);

        self::assertFalse(mb_check_encoding($halved, 'UTF-8'));
    }

    /**
     * **A very long line is longer than anything a memo asks for.** RFC 6350
     * §3.2 says lines "SHOULD NOT be longer than 75 octets", and a reader that
     * believed that would be a reader a single long line could take down.
     */
    public function testAVeryLongLineIsLongerThanAnyMemoAsksFor(): void
    {
        $long = Mutations::apply('a very long line', Mutations::seeds()['a card'] ?? '', 1);

        self::assertGreaterThan(100_000, strlen($long));
    }

    /**
     * **A deep nesting opens far more components than it closes**, which is
     * the input that asks whether a reader keeps a stack and whether that
     * stack has an end.
     */
    public function testADeepNestingOpensMoreThanItCloses(): void
    {
        $deep = Mutations::apply('a deep nesting', Mutations::seeds()['a calendar'] ?? '', 1);

        self::assertGreaterThan(500, substr_count($deep, 'BEGIN:'));
        self::assertGreaterThan(400, substr_count($deep, 'BEGIN:') - substr_count($deep, 'END:'));
    }

    /**
     * **A hostile value leaves the object readable**, and that is the whole
     * point of it. A broken byte is usually refused at the line it is in, so
     * the code that runs *after* an object has been understood — the
     * validator, the converter, the expansion — never sees it. A value that
     * is well formed as a line and hostile as a value does reach them.
     */
    public function testAHostileValueLeavesTheObjectReadable(): void
    {
        $read = 0;

        foreach (range(1, 20) as $seed) {
            $input = Mutations::apply('a hostile value', Mutations::seeds()['a calendar'] ?? '', $seed);

            try {
                $read += iterator_to_array((new Reader($input))->objects(), false) === [] ? 0 : 1;
            } catch (ParseError) {
                // A refusal here is the reader's right; what is measured is
                // how often it does not have to use it.
            }
        }

        self::assertGreaterThan(15, $read);
    }

    /**
     * **A hostile value finds a place even in a shell.** An object whose only
     * lines are the ones holding it together has no value to spoil, and the
     * honest answer to that is to add one rather than to hand the sweep its
     * input back unchanged.
     */
    public function testAHostileValueFindsAPlaceEvenInAShell(): void
    {
        $shell = "BEGIN:VCARD\r\nVERSION:3.0\r\nEND:VCARD\r\n";

        self::assertStringContainsString('NOTE:', Mutations::apply('a hostile value', $shell, 2));
    }

    /**
     * **A hostile value keeps the parameters that were there**, so that a
     * `DTSTART;TZID=Europe/Berlin` still asks the time zone code to look that
     * zone up: the parameters are half of what the code after the reader
     * believes about a property.
     */
    public function testAHostileValueKeepsTheParametersThatWereThere(): void
    {
        $calendar = Mutations::seeds()['a calendar'] ?? '';
        $kept = 0;

        foreach (range(1, 20) as $seed) {
            $kept += substr_count(Mutations::apply('a hostile value', $calendar, $seed), ';TZID=Europe/Berlin:');
        }

        self::assertGreaterThan(30, $kept);
    }

    /**
     * **A hostile rule is always a rule**, which is what gets the recurrence
     * engine to run rather than the reader to refuse: a rule that matches
     * nothing, or counts to ten thousand, or has an `INTERVAL` of zero is
     * well formed by §3.3.10's grammar and hard work by any other measure.
     * R-RRULE-04's hard limit is the thing that has to answer it.
     */
    public function testAHostileRuleIsAlwaysARule(): void
    {
        $text = Mutations::seeds()['a card'] ?? '';

        foreach (range(1, 20) as $seed) {
            $hostile = Mutations::apply('a hostile rule', $text, $seed);

            self::assertStringContainsString('RRULE:FREQ=', $hostile, 'seed ' . $seed);
        }
    }

    /**
     * **A hostile rule replaces the rule that was there**, rather than adding
     * a second one beside it: a component may carry one `RRULE`, and a sweep
     * that tested two of them would be testing the validator's cardinality
     * rule over and over instead of the engine.
     */
    public function testAHostileRuleReplacesTheRuleThatWasThere(): void
    {
        $calendar = Mutations::seeds()['a calendar'] ?? '';
        $hostile = Mutations::apply('a hostile rule', $calendar, 4);

        self::assertSame(1, substr_count($hostile, 'RRULE:'));
        self::assertStringNotContainsString('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10', $hostile);
    }

    /**
     * **An unknown mutation is refused**, so that a typo in the driver is an
     * error and not a run that quietly tests nothing.
     */
    public function testAnUnknownMutationIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/no such mutation/');

        Mutations::apply('a mutation nobody wrote', 'x', 1);
    }

    /**
     * How many bytes two strings of the same length differ in.
     */
    private static function bytesApart(string $one, string $other): int
    {
        $apart = 0;

        foreach (str_split($one) as $at => $byte) {
            if ($byte !== ($other[$at] ?? '')) {
                ++$apart;
            }
        }

        return $apart;
    }
}
