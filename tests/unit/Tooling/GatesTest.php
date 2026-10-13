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

namespace DavServices\Tests\Unit\Tooling;

use Gates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../bin/lib/Gates.php';

/**
 * Test list for what is checked before a commit and before a push.
 *
 * **The point of running the gates locally is to learn the answer before
 * GitHub does.** The point of splitting them in two is that a check nobody
 * waits for is a check somebody turns off: `git commit --no-verify` is one
 * flag away, and a hook that costs minutes teaches people to reach for it.
 *
 * The requirements already draw that line for the slowest gate of all —
 * R-QS-26: „Der Mutationslauf ist zu langsam für jeden Commit und läuft
 * nächtlich sowie vor jedem Release." The same reasoning puts the sharded
 * coverage run and the fuzz sweep before a **push** rather than before every
 * commit, and leaves the gates that cost seconds where a mistake is made.
 *
 * ## What these tests hold it to
 *
 * - **a commit is checked by gates CI also runs**, so passing here cannot
 *   mean something different from passing there;
 * - **a push runs `composer check` itself**, rather than a list beside it
 *   that somebody has to remember to keep in step;
 * - **the slow gates are named and excluded on purpose**, not forgotten;
 * - **every gate named exists**, because a typo in a hook is a hook that
 *   fails for the wrong reason.
 */
final class GatesTest extends TestCase
{
    /**
     * **A push runs the whole of `composer check`, and runs it by name.**
     *
     * That is the one decision here that keeps the promise honest: CI runs
     * `check`, so a push that ran a list of its own would be a second list to
     * keep in step — and the day somebody adds a gate to `check` and forgets
     * the hook is the day the hook starts lying.
     */
    public function testAPushRunsTheWholeOfComposerCheck(): void
    {
        self::assertContains('check', Gates::before('push', self::manifest()));
        self::assertSame('gates:push', Gates::scriptFor('push'));
    }

    /**
     * **A commit is checked by the gates that cost seconds.**
     */
    public function testACommitIsCheckedByTheGatesThatCostSeconds(): void
    {
        $gates = Gates::before('commit', self::manifest());

        self::assertNotSame([], $gates);
        self::assertSame($gates, array_values(array_unique($gates)));
        self::assertSame('gates:commit', Gates::scriptFor('commit'));
    }

    /**
     * **Every gate a commit runs is one `composer check` runs too.** A local
     * check that enforced something CI does not would refuse work that is
     * perfectly good; one that enforced less would be a false green.
     */
    public function testEveryGateACommitRunsIsOneTheWholeCheckRunsToo(): void
    {
        $check = self::scripts()['check'] ?? null;

        self::assertIsArray($check);

        $inCheck = [];

        foreach ($check as $entry) {
            self::assertIsString($entry);

            $inCheck[] = ltrim($entry, '@');
        }

        foreach (Gates::before('commit', self::manifest()) as $gate) {
            if (str_starts_with($gate, 'php ')) {
                continue;
            }

            self::assertContains($gate, $inCheck, $gate);
        }
    }

    /**
     * **And every gate named is a script that exists.** A typo would make the
     * hook fail for a reason that has nothing to do with the code.
     *
     * @param non-empty-string $stage
     */
    #[DataProvider('everyStage')]
    public function testEveryGateNamedIsAScriptThatExists(string $stage): void
    {
        $scripts = self::scripts();

        foreach (Gates::before($stage, self::manifest()) as $gate) {
            if (str_starts_with($gate, 'php ')) {
                continue;
            }

            self::assertArrayHasKey($gate, $scripts, $gate);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyStage(): iterable
    {
        foreach (Gates::STAGES as $stage) {
            yield $stage => [$stage];
        }
    }

    /**
     * **The slow gates are left out of a commit on purpose.**
     *
     * R-QS-26 draws this line for the mutation run, and the same reasoning
     * reaches the sharded coverage run — minutes, because Xdebug's branch
     * check makes every call slower than the last — and the fuzz sweep, which
     * spends tens of thousands of runs. Both belong to a push, where they are
     * paid for once per claim rather than once per save point.
     */
    public function testTheSlowGatesAreLeftOutOfACommitOnPurpose(): void
    {
        $gates = Gates::before('commit', self::manifest());

        self::assertNotContains('test:coverage', $gates);
        self::assertNotContains('check:coverage', $gates);
        self::assertNotContains('check:fuzz', $gates);
        self::assertNotContains('mutation', $gates);
    }

    /**
     * **An unknown stage is refused**, so that a hook named after something
     * nobody wrote is an error rather than a hook that checks nothing.
     */
    public function testAnUnknownStageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no gates/');

        Gates::scriptFor('merge');
    }

    /**
     * **A stage the manifest does not define is refused**, and the message
     * names the script that is missing. Somebody renaming a Composer script
     * would otherwise get a hook that quietly checked nothing.
     */
    public function testAStageTheManifestDoesNotDefineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/gates:commit/');

        Gates::before('commit', ['scripts' => ['check' => []]]);
    }

    /**
     * **It says what only CI can try**, because a green run here is not a
     * green run there and a hook that implied otherwise would be the most
     * expensive kind of wrong.
     *
     * The other PHP versions and Litmus are out of reach on any one machine.
     * `intl` is the interesting one: R-ENV-03 asks that the library work
     * without it, and CI proves both sides — so whichever side this machine
     * is on, the other is the one it cannot speak for.
     */
    public function testItSaysWhatOnlyTheCiCanTry(): void
    {
        $withIntl = implode(' | ', Gates::whatOnlyTheCiCanTry(true));
        $without = implode(' | ', Gates::whatOnlyTheCiCanTry(false));

        self::assertStringContainsString('8.3', $withIntl);
        self::assertStringContainsString('8.4', $withIntl);
        self::assertStringContainsString('Litmus', $withIntl);

        self::assertStringContainsString('without intl', $withIntl);
        self::assertStringContainsString('with intl', $without);
        self::assertStringNotContainsString('without intl', $without);
    }

    /**
     * `composer.json`, decoded. The one source of truth for what a stage
     * runs, which is why these tests read it rather than a list of their own.
     *
     * @return array<array-key, mixed>
     */
    private static function manifest(): array
    {
        $json = file_get_contents(__DIR__ . '/../../../composer.json');

        self::assertIsString($json);

        $manifest = json_decode($json, true);

        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * Its `scripts` block.
     *
     * @return array<array-key, mixed>
     */
    private static function scripts(): array
    {
        $scripts = self::manifest()['scripts'] ?? null;

        self::assertIsArray($scripts);

        return $scripts;
    }
}
