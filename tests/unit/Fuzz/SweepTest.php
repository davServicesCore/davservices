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
use DavServices\Tests\Fuzz\Sweep;
use DavServices\Tests\Fuzz\Targets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Test list for the catalogue of inputs one fuzz run works through. R-QS-19,
 * P4-14.
 *
 * **A run is every object crossed with every mutation crossed with a range of
 * seeds**, and the only thing that makes it worth running is that a finding
 * can be brought back. So each input carries a name that says which object,
 * which mutation and which seed made it — the three things somebody needs to
 * see it again — and the sweep is a plain cross product, so that adding one
 * mutation adds it everywhere rather than in the one place somebody
 * remembered.
 *
 * ## The run itself is not here, and that is the point
 *
 * This list checks the catalogue and the bookkeeping with targets that stand
 * in for the real ones. **The sweep against the real parsers belongs to
 * `composer check:fuzz`**, because this suite runs under Xdebug's path
 * coverage, where everything costs fifty times what it costs in earnest. A
 * single seed of the real thing was measured here at over ten minutes — a
 * comparable shard without it takes twenty seconds — and `Run`'s time limit
 * would have reported an endless loop on every input it did get through. A
 * sweep measured like that measures Xdebug, which is the whole reason the
 * driver is a script.
 */
#[CoversClass(Sweep::class)]
final class SweepTest extends TestCase
{
    /**
     * **Every object meets every mutation at every seed**, which is what
     * makes the run a sweep rather than a selection somebody curated.
     */
    public function testEveryObjectMeetsEveryMutationAtEverySeed(): void
    {
        $inputs = iterator_to_array(Sweep::inputs(3), true);

        self::assertCount(count(Mutations::seeds()) * count(Mutations::names()) * 3, $inputs);
    }

    /**
     * **An input says how to make it again**: which object, which mutation,
     * which seed. A finding whose input cannot be reproduced is a rumour.
     */
    public function testAnInputSaysHowToMakeItAgain(): void
    {
        $names = array_keys(iterator_to_array(Sweep::inputs(1), true));

        self::assertContains('a card / a flipped byte / seed 1', $names);
        self::assertContains('a calendar / a deep nesting / seed 1', $names);
    }

    /**
     * **A sweep starts where it is told to**, so that a second run can go on
     * past the seeds the first one spent rather than repeating them.
     */
    public function testASweepStartsWhereItIsToldTo(): void
    {
        $names = array_keys(iterator_to_array(Sweep::inputs(2, 100), true));

        self::assertContains('a card / a cut / seed 100', $names);
        self::assertContains('a card / a cut / seed 101', $names);
        self::assertNotContains('a card / a cut / seed 1', $names);
    }

    /**
     * **The input under a name is the one the name makes**, which is the
     * promise a report leans on: a reader takes the three parts of the name,
     * asks {@see Mutations} for that input and gets the same bytes back.
     */
    public function testTheInputUnderANameIsTheOneTheNameMakes(): void
    {
        $inputs = iterator_to_array(Sweep::inputs(1, 42), true);

        self::assertSame(
            Mutations::apply('a cut', Mutations::seeds()['a card'] ?? '', 42),
            $inputs['a card / a cut / seed 42'] ?? '',
        );
    }

    /**
     * **A sweep of nothing is nothing**, not a run that quietly sweeps
     * everything: a budget of zero is a mistake in the caller, and the honest
     * answer to it is an empty run rather than a surprise.
     */
    public function testASweepOfNothingIsNothing(): void
    {
        self::assertSame([], iterator_to_array(Sweep::inputs(0), true));
    }

    /**
     * **Every target there is, is one the sweep hands an input to**, so that
     * a target added to {@see Targets} is swept without anybody having to
     * remember a second place.
     */
    public function testEverySweptTargetIsATargetThereIs(): void
    {
        self::assertSame(Targets::names(), array_keys(Sweep::every()));
    }

    /**
     * **A finding names its input and its target**, the two things that turn
     * a report into a test somebody can write.
     */
    public function testAFindingNamesItsInputAndItsTarget(): void
    {
        $findings = iterator_to_array(
            Sweep::findings(1, 1, ['reading strictly' => static fn (): never => throw new TypeError('broken')]),
            false,
        );

        self::assertCount(count(Mutations::seeds()) * count(Mutations::names()), $findings);
        self::assertStringContainsString('reading strictly', $findings[0] ?? '');
        self::assertStringContainsString('seed 1', $findings[0] ?? '');
        self::assertStringContainsString('TypeError', $findings[0] ?? '');
    }

    /**
     * **The run says what it is about to do, before it does it.** A memory
     * error is not an exception: it ends the process, and there is nothing
     * left to ask afterwards which input caused it. The only report such a
     * failure allows is one written down in advance, so the sweep offers to
     * say where it is before each run rather than after.
     */
    public function testTheRunSaysWhatItIsAboutToDoBeforeItDoesIt(): void
    {
        $announced = [];

        iterator_to_array(
            Sweep::findings(1, 9, ['reading strictly' => static fn (): int => 1], static function (string $at) use (&$announced): void {
                $announced[] = $at;
            }),
            false,
        );

        self::assertCount(count(Mutations::seeds()) * count(Mutations::names()), $announced);
        self::assertContains('a card / a cut / seed 9, reading strictly', $announced);
    }

    /**
     * **Every target is swept**, or the run would be measuring the one step
     * somebody happened to wire up.
     */
    public function testEveryTargetIsSwept(): void
    {
        $swept = [];
        $targets = [];

        foreach (Targets::names() as $name) {
            $targets[$name] = static function (string $input) use (&$swept, $name): int {
                $swept[$name] = true;

                return strlen($input);
            };
        }

        iterator_to_array(Sweep::findings(1, 1, $targets), false);

        self::assertSame(array_fill_keys(Targets::names(), true), $swept);
    }
}
