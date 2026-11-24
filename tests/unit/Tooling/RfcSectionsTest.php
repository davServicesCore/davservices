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
use RfcSections;

require_once __DIR__ . '/../../../bin/lib/RfcSections.php';

/**
 * Test list for reading the sections of a memo out of its own text.
 *
 * R-CODE-12 wants a conformance matrix that names, **per section**, the state
 * and the test. A matrix is only worth having if it is complete, and the one
 * authority on which sections there are is the memo — so the gate behind the
 * matrix asks the text rather than a list somebody kept by hand.
 *
 * **A heading is a line that starts in the first column.** RFC text indents
 * its prose by three spaces and its headings by none, which is what tells a
 * heading from the table of contents — where the same numbers and titles
 * appear, indented, with a run of dots and a page number after them.
 *
 * The numbers alone are read, not the titles: RFC 6638's Appendix B.6 wraps
 * its title across two lines, and a gate that compared titles would be a gate
 * about typesetting.
 */
final class RfcSectionsTest extends TestCase
{
    /**
     * @param string $text The memo, or as much of it as the case is about
     * @param list<string> $numbers The sections it holds, in document order
     */
    #[DataProvider('memos')]
    public function testItReadsTheSectionsOutOfTheText(string $text, array $numbers): void
    {
        self::assertSame($numbers, RfcSections::of($text));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function memos(): iterable
    {
        yield 'a heading in the first column' => [
            "5.  Request for Busy Time Information\n",
            ['5'],
        ];

        yield 'however deeply it is numbered' => [
            "3.2.10.1.  PUT\n",
            ['3.2.10.1'],
        ];

        yield 'an appendix, and the sections under it' => [
            "Appendix A.  Scheduling Privileges Summary\n\nA.1.  Scheduling Inbox Privileges\n",
            ['A', 'A.1'],
        ];

        yield 'in the order the memo puts them' => [
            "2.  Scheduling Support\n\n2.1.  Scheduling Outbox Collection\n\n3.  Scheduling Operations\n",
            ['2', '2.1', '3'],
        ];

        // **The table of contents says the same things**, which is why the
        // first column is what decides: every line of it is indented.
        yield 'the table of contents is not a heading' => [
            "Table of Contents\n\n   1. Introduction ...........5\n      1.1. Terminology ......6\n",
            [],
        ];

        // RFC text puts the memo's name and date at the top of every page and
        // the authors at the bottom. Neither is a section, and neither is the
        // form feed between them.
        yield 'a running head is not a heading' => [
            "RFC 6638                    CalDAV Scheduling                  June 2012\n",
            [],
        ];

        yield 'a page footer is not a heading' => [
            "Daboo & Desruisseaux         Standards Track                    [Page 1]\n",
            [],
        ];

        // **Two spaces after the dot, which is what RFC typesetting uses.**
        // An ordinary sentence that happens to begin with a number and a full
        // stop is prose, and prose is indented — but the rule is written down
        // rather than left to the indentation alone.
        yield 'one space after the number is not a heading' => [
            "5. Request for Busy Time Information\n",
            [],
        ];

        yield 'a heading with nothing after it is no heading' => [
            "5.  \n",
            [],
        ];

        yield 'nothing at all' => ['', []];
    }

    /**
     * **Asked of the memo this repository keeps**, because a parser that
     * reads the cases above and not the real thing would be a parser about
     * the cases. RFC 6638 is the one the matrix covers first.
     */
    public function testItReadsEveryNumberedSectionOfRfc6638(): void
    {
        $sections = RfcSections::of(self::theMemo());

        self::assertContains('1', $sections);
        self::assertContains('3.2.10.1', $sections);
        self::assertContains('6.2.4', $sections);
        self::assertContains('A.2', $sections);
        self::assertContains('B.8', $sections);

        // The last of the twelve numbered sections before the back matter,
        // which is what says the walk did not stop at a page break.
        self::assertContains('12.4.2', $sections);

        self::assertSame($sections, array_values(array_unique($sections)));
        self::assertGreaterThan(100, count($sections));
    }

    /**
     * **And it finds nothing that is not there**: every number it answers is
     * one the memo prints at the start of a line.
     */
    public function testEverySectionItAnswersIsInTheText(): void
    {
        $memo = self::theMemo();

        foreach (RfcSections::of($memo) as $number) {
            $heading = preg_match('/^[A-Z]/', $number) === 1
                ? (str_contains($number, '.') ? $number : 'Appendix ' . $number)
                : $number;

            self::assertStringContainsString("\n" . $heading . '.  ', $memo);
        }
    }

    /**
     * **Skipped where the memo is not here, rather than failed.**
     * `docs/referenzen/README.md` says why the texts are not committed — "sie
     * mitzuliefern hieße, fremde Dokumente zu verteilen" — and
     * `download-rfcs.sh` fetches them on demand. So a clean checkout has none
     * of them, and a case that insisted would fail the build over a file the
     * repository deliberately does not carry.
     *
     * What does not depend on the memo is what matters: the rules above are
     * asked of text written out in the case, and the gate is held against
     * `docs/rfc-sections/`, which **is** committed. These two cases are the
     * sanity check that the rules describe the real thing, and they run
     * wherever somebody has fetched it.
     */
    private static function theMemo(): string
    {
        $path = __DIR__ . '/../../../docs/referenzen/rfc/rfc6638.txt';

        // Asked before it is read, not caught afterwards: this suite has
        // `failOnWarning`, so a read of a file that is not there would fail
        // the case it was meant to skip.
        if (!is_file($path)) {
            self::markTestSkipped('No copy of RFC 6638 here; see docs/referenzen/README.md.');
        }

        $text = file_get_contents($path);

        return is_string($text) ? $text : self::fail('The copy of RFC 6638 here cannot be read.');
    }
}
