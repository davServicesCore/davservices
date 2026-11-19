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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scanner;

require_once __DIR__ . '/../../../bin/lib/Scanner.php';

/**
 * Test list for the DocBlock that describes nothing.
 *
 * PHP attaches the **last** DocBlock before a declaration. So when something
 * lands between a DocBlock and the element it was written for — an insertion
 * anchored on the signature or on an attribute, say — the words of the first
 * are gone, and the element carries somebody else's.
 *
 * The gate that asks a declaration for its DocBlock cannot see this: it walks
 * backwards from the declaration to the nearest DocBlock and finds one every
 * time. Asked the other way round the answer is plain, and it needs no
 * exceptions: **a DocBlock immediately followed by another DocBlock describes
 * nothing.**
 *
 * That is a statement about any PHP file rather than about what ships, so it
 * is asked of every file the repository owns — which is where it matters,
 * since four of the seven strandings found here were in tests.
 */
final class ScannerTest extends TestCase
{
    /**
     * **Asked the other way round**: not "has this declaration a DocBlock?"
     * but "has this DocBlock anything to describe?".
     *
     * @param string $code The file's source
     * @param list<int> $stranded Lines of the DocBlocks that describe nothing
     */
    #[DataProvider('filesWithAndWithoutAStrandedDocBlock')]
    public function testADocBlockFollowedByAnotherDocBlockDescribesNothing(
        string $code,
        array $stranded,
    ): void {
        self::assertSame($stranded, Scanner::strandedDocBlocks($code));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function filesWithAndWithoutAStrandedDocBlock(): iterable
    {
        // The accident itself, and the shape of all seven found in this
        // repository: the first DocBlock keeps its words and loses its
        // element.
        yield 'a DocBlock stranded above another' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /**
                     * The words that get lost.
                     */
                    /**
                     * The DocBlock PHP attaches instead.
                     */
                    public function doIt(): void {}
                }
                PHP,
            [5],
        ];

        // **An attribute by itself strands nothing**, and must not be
        // refused: nothing has taken the words off the method, which still
        // carries them across the attribute.
        yield 'a DocBlock above an attribute' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /**
                     * Describes the method below, across the attribute.
                     */
                    #[DataProvider('cases')]
                    public function doIt(): void {}
                }
                PHP,
            [],
        ];

        // **But an attribute between the two does not rescue the first
        // either** — and this is the shape an insertion anchored on the
        // signature leaves behind, rather than on the attribute. PHP attaches
        // the last DocBlock here as anywhere else, so the words above the
        // attribute describe nothing.
        yield 'an attribute between the two' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /**
                     * The words that get lost.
                     */
                    #[DataProvider('cases')]
                    /**
                     * The DocBlock PHP attaches instead.
                     */
                    public function doIt(): void {}
                }
                PHP,
            [5],
        ];

        // **An argument may bring brackets of its own.** Stepping over the
        // attribute by its first `]` would stop inside this one and read the
        // rest of it as code, which would reset the search and lose the
        // stranding.
        yield 'an attribute carrying a bracket' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /**
                     * The words that get lost.
                     */
                    #[Of([1, 2])]
                    /**
                     * The DocBlock PHP attaches instead.
                     */
                    public function doIt(): void {}
                }
                PHP,
            [5],
        ];

        // Two DocBlocks with their own elements between them are two
        // documented methods, not a pair.
        yield 'two DocBlocks that each have an element' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /** Describes doIt(). */
                    public function doIt(): void {}

                    /** Describes doItAgain(). */
                    public function doItAgain(): void {}
                }
                PHP,
            [],
        ];

        // Three in a row are two accidents, not one: each of the first two
        // has lost its element.
        yield 'three DocBlocks in a row' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /** First. */
                    /** Second. */
                    /** Third. */
                    public function doIt(): void {}
                }
                PHP,
            [5, 6],
        ];

        // **An ordinary comment does not rescue the first.** PHP attaches the
        // last DocBlock either way, so a note left between the two changes
        // nothing about what the words above it describe — which is nothing.
        yield 'a comment between the two' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /** The words that get lost. */
                    // A note somebody left between them.
                    /** The DocBlock PHP attaches instead. */
                    public function doIt(): void {}
                }
                PHP,
            [5],
        ];

        // A file DocBlock above a `declare` is the shape this repository's own
        // tooling uses, and nothing but code follows it.
        yield 'a file DocBlock above a declaration' => [
            <<<'PHP'
                <?php

                /**
                 * What this file is for.
                 */
                declare(strict_types=1);

                class Something
                {
                    /** Describes doIt(). */
                    public function doIt(): void {}
                }
                PHP,
            [],
        ];

        // **A DocBlock with nothing at all after it is a different question**,
        // and this guard does not answer it: what it is here for is the pair,
        // where words were taken off an element that still exists. A trailing
        // one is dead text, and refusing it would be a second rule wearing
        // the first one's name.
        yield 'a DocBlock at the end of the file' => [
            <<<'PHP'
                <?php

                class Something
                {
                    /** Describes doIt(). */
                    public function doIt(): void {}
                }

                /** Nothing follows this. */
                PHP,
            [],
        ];

        yield 'a file with no DocBlock at all' => [
            <<<'PHP'
                <?php

                class Something
                {
                    public function doIt(): void {}
                }
                PHP,
            [],
        ];
    }

    /**
     * **The question is asked of every file the repository owns**, not only of
     * what ships: "needs documenting" is a rule about the package, while "this
     * DocBlock describes nothing" is true of any PHP file — and the accident
     * happens most often in a test.
     */
    public function testEveryOwnFileIsLookedAt(): void
    {
        $files = self::ownFilesFromTheRoot();

        self::assertContains('src/davservices/Dav/Server.php', $files);
        self::assertContains('tests/unit/Tooling/ScannerTest.php', $files);
        self::assertContains('bin/check-docblocks.php', $files);
        self::assertContains('autoload.php', $files);
    }

    /**
     * **And of nothing else.** `vendor/` is somebody else's code and `build/`
     * is output; a gate that walked into either would report findings nobody
     * in this repository can fix.
     */
    public function testNothingBorrowedOrBuiltIsLookedAt(): void
    {
        foreach (self::ownFilesFromTheRoot() as $file) {
            self::assertStringStartsNotWith('vendor/', $file);
            self::assertStringStartsNotWith('build/', $file);
        }
    }

    /**
     * Whatever ships is among them, so broadening the question cannot narrow
     * it by accident.
     */
    public function testWhatShipsIsAmongThem(): void
    {
        self::assertSame(
            [],
            array_diff(Scanner::productionFiles(), Scanner::repositoryFiles()),
        );
    }

    /**
     * The repository's own files, named the way the root names them.
     *
     * `Scanner::rel()` answers in the separator of the host, so the paths are
     * laid on one spelling here — otherwise this test would read Windows'
     * answer as a different file from the one CI sees.
     *
     * @return list<string>
     */
    private static function ownFilesFromTheRoot(): array
    {
        return array_map(
            static fn (string $file): string => str_replace('\\', '/', Scanner::rel($file)),
            Scanner::repositoryFiles(),
        );
    }
}
