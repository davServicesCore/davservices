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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\ReportDepth;
use DavServices\Exception\BadRequest;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3253 §3.6 and RFC 3744 §9.4 and §9.5.
 *
 * **A missing `Depth` on a report means `0`.** That is the opposite of
 * `PROPFIND`, where RFC 4918 §9.1 makes it mean infinity — and getting it the
 * wrong way round is not a small mistake in either direction. Read as
 * infinity, a report that should have looked at one resource walks a tree.
 * Read as zero where infinity was meant, a client is quietly told there is
 * nothing there.
 *
 * Most reports of RFC 3744 are defined at `Depth: 0` and nowhere else, and
 * they all say so with the same sentence. So the sentence lives in one place:
 * three reports asking it three times is three chances to write `infinity`
 * where `0` belongs.
 */
#[CoversClass(ReportDepth::class)]
final class ReportDepthTest extends TestCase
{
    public function testDepthZeroIsWhatWasAskedFor(): void
    {
        $this->expectNotToPerformAssertions();

        ReportDepth::mustBeZero($this->report('0'));
    }

    /**
     * **RFC 3253 §3.6: no header means `0`.** A report that refused it would
     * refuse the request RFC 3744 §9.5.1 shows a client making, and it is the
     * request every client makes.
     */
    public function testAMissingDepthIsDepthZero(): void
    {
        $this->expectNotToPerformAssertions();

        ReportDepth::mustBeZero(new Request('REPORT', '/principals'));
    }

    /**
     * §9.4 and §9.5, in the same words: "other values result in a 400 (Bad
     * Request) error response".
     */
    #[DataProvider('depthsThatAreNotZero')]
    public function testEveryOtherDepthIsRefused(string $depth): void
    {
        $this->expectException(BadRequest::class);

        ReportDepth::mustBeZero($this->report($depth));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthsThatAreNotZero(): iterable
    {
        yield 'one' => ['1'];

        yield 'infinity' => ['infinity'];

        // Whatever a client meant by this, it did not mean nothing, and
        // answering as though it had said `0` would answer a question nobody
        // asked.
        yield 'nonsense' => ['deep'];

        yield 'nothing at all' => [''];
    }

    /**
     * **And the reports that do walk a collection read the same sentence.**
     * RFC 4791 §7.8 and §7.10 both say "The request MAY include a Depth
     * header. If no Depth header is included, Depth:0 is assumed", and
     * RFC 4918 §10.2 writes the value as `("0" | "1" | "infinity")`.
     *
     * `infinity` comes back as a depth deeper than any tree, so that a walk
     * counting down through it needs no case of its own.
     *
     * @param string|null $depth What the header says, or nothing at all
     */
    #[DataProvider('depthsAndWhatTheyMean')]
    public function testADepthAReportMayWalkIsRead(?string $depth, int $meaning): void
    {
        $request = $depth === null
            ? new Request('REPORT', '/calendars/work')
            : $this->report($depth);

        self::assertSame($meaning, ReportDepth::of($request));
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function depthsAndWhatTheyMean(): iterable
    {
        yield 'none, which is zero' => [null, 0];
        yield 'zero' => ['0', 0];
        yield 'one' => ['1', 1];
        yield 'infinity' => ['infinity', ReportDepth::ENDLESS];

        // RFC 5234 §2.3 makes the grammar's own strings case-insensitive, so a
        // client that shouted it still said infinity.
        yield 'infinity, loudly' => ['Infinity', ReportDepth::ENDLESS];
    }

    /**
     * A fourth value is a request the report has no meaning for rather than
     * one to guess at, and the refusal says which three it has.
     *
     * @param string $depth What the header says
     */
    #[DataProvider('depthsNoReportIsDefinedAt')]
    public function testADepthNoReportIsDefinedAtIsRefused(string $depth): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('0, 1 or infinity');

        ReportDepth::of($this->report($depth));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthsNoReportIsDefinedAt(): iterable
    {
        yield 'two' => ['2'];
        yield 'nonsense' => ['deep'];
        yield 'nothing at all' => [''];
    }

    private function report(string $depth): Request
    {
        return new Request('REPORT', '/principals', headers: new Headers(['Depth' => $depth]));
    }
}
