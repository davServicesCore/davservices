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
use DavServices\Tests\Fuzz\Run;
use DavServices\Tests\Fuzz\Targets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what the fuzzer does with an input. R-QS-19, P4-14.
 *
 * > P4-14: iCalendar und vCard, keine Endlosschleife, kein Speicherfehler,
 * > nur definierte Ausnahmen.
 *
 * **A fuzzer that only reads reaches only the reader.** Half of what the
 * library does with an object happens after it has been read — it is
 * serialised again, validated, converted between vCard versions, expanded
 * into a series, read as a time zone — and every one of those steps believes
 * things about the object it was handed. A target is one such step, named, so
 * that a finding says which one.
 *
 * ## What a target owes
 *
 * - **it gets through a valid object**, or it is wired to something that
 *   always refuses and the fuzzer would be measuring nothing;
 * - **it answers rubbish with a refusal**, not with a `TypeError` — which is
 *   the whole claim of this chunk, tested here on the one input everybody can
 *   check by eye before it is tested on thousands nobody can.
 */
#[CoversClass(Targets::class)]
final class TargetsTest extends TestCase
{
    /**
     * How long these cases give a target, which is **not** the campaign's
     * budget.
     *
     * {@see Run::SECONDS} is two seconds, and that is the right number where
     * it is measured: the campaign runs one process, with no coverage driver
     * loaded, and the slowest target there takes five milliseconds.
     *
     * **These cases do not run there.** They run inside the sharded coverage
     * run — eleven instrumented processes over as many cores as the machine
     * has — and a wall clock in one of them measures the machine as much as
     * the parser. Measured rather than assumed: the same parse of the same
     * time zone came out at 5 ms on a quiet machine, 2.3 s in the local
     * coverage run, and 2.07 s on a CI runner. The first reading of the three
     * is the parser; the other two are the scheduler.
     *
     * So the budget here is one no instrumentation can reach, and the claim
     * these cases make is the one they are about: every target gets through a
     * valid object, and answers rubbish with a refusal. **Whether a parser
     * hangs is the campaign's question**, asked where the clock means
     * something — `composer check:fuzz`, and the `Fuzzing` job in CI.
     */
    private const PATIENCE = 30.0;
    /**
     * **There is more than reading**, and each step has its own name so that
     * a finding says which one of them broke.
     */
    public function testThereIsMoreThanReading(): void
    {
        $names = Targets::names();

        self::assertGreaterThan(4, count($names));
        self::assertSame($names, array_values(array_unique($names)));
    }

    /**
     * **Both memos are fuzzed**, which R-QS-19 asks for by name: "Fuzzing für
     * den iCalendar- und vCard-Parser".
     */
    public function testBothMemosAreFuzzed(): void
    {
        $seeds = implode(' ', array_keys(Mutations::seeds()));

        self::assertStringContainsString('card', $seeds);
        self::assertStringContainsString('calendar', $seeds);
        self::assertStringContainsString('card', implode(' ', Targets::names()));
    }

    /**
     * **What carries a photograph is fuzzed too.**
     *
     * R-QS-19 asks for three things: "Fuzzing für den iCalendar- und
     * vCard-Parser sowie für die **Bildverarbeitung**." The third has no
     * counterpart here, and **the requirements decide that themselves** —
     * R-ENV-02: „`gd` und `imagick` sind Anwendungsschicht und damit
     * außerhalb des Geltungsbereichs." Image processing belongs to the
     * embedding application; a protocol library has none to fuzz and will
     * not grow any.
     *
     * What it does have is the thing that **carries** an image into a card:
     * RFC 6350 gives `PHOTO` (§6.2.4), `LOGO` (§6.6.3), `SOUND` (§6.7.5) and
     * `KEY` (§6.8.1) the URI value type, and RFC 2397's `data:` is how the
     * bytes travel inside one. That reader is fuzzed, which is as far as
     * R-QS-19 reaches inside R-ENV-02's boundary.
     */
    public function testWhatCarriesAPhotographIsFuzzedToo(): void
    {
        self::assertContains('reading a photo', Targets::names());
    }

    /**
     * **Every target gets through the objects the memos publish.** A target
     * that refused them would make every run after it meaningless: the
     * refusal would be the target's, not the input's.
     *
     * @param non-empty-string $target
     */
    #[DataProvider('everyTarget')]
    public function testEveryTargetGetsThroughAValidObject(string $target): void
    {
        foreach (Mutations::seeds() as $name => $text) {
            self::assertNull(Run::of($text, Targets::of($target), self::PATIENCE), $target . ' on ' . $name);
        }
    }

    /**
     * **Every target answers rubbish with a refusal**, which is the claim of
     * the whole chunk on an input small enough to read: no `TypeError`, no
     * warning, no hang.
     *
     * @param non-empty-string $target
     */
    #[DataProvider('everyTarget')]
    public function testEveryTargetAnswersRubbishWithARefusal(string $target): void
    {
        foreach (['', 'rubbish', "\x00\xff", 'BEGIN:', "BEGIN:VCARD\r\n"] as $rubbish) {
            self::assertNull(Run::of($rubbish, Targets::of($target), self::PATIENCE), $target);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyTarget(): iterable
    {
        foreach (Targets::names() as $name) {
            yield $name => [$name];
        }
    }

    /**
     * **A target is given the input**, and this is the test that would catch
     * a target that looked at nothing: a changed input changes what it does.
     */
    public function testATargetIsGivenTheInput(): void
    {
        $read = Targets::of('reading strictly');

        self::assertSame(1, $read(Mutations::seeds()['a card'] ?? ''));
        self::assertSame(2, $read((Mutations::seeds()['a card'] ?? '') . (Mutations::seeds()['a card'] ?? '')));
    }

    /**
     * **An unknown target is refused**, so that a typo in the driver is an
     * error and not a run that quietly tests nothing.
     */
    public function testAnUnknownTargetIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/no such target/');

        Targets::of('reading the tea leaves');
    }
}
