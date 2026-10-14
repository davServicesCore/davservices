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

namespace DavServices\Tests\Unit\CardDav\Filter;

use DavServices\CardDav\Filter\Test;
use DavServices\Exception\BadRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `test` attribute of RFC 6352 §10.5 and §10.5.1.
 *
 * Two elements carry it, and the DTD gives it the same default in both:
 *
 *     <!ATTLIST filter test (anyof | allof) "anyof">
 *     <!ATTLIST prop-filter name CDATA #REQUIRED
 *                           test (anyof | allof) "anyof">
 */
#[CoversClass(Test::class)]
final class TestTest extends TestCase
{
    /**
     * **The default is `anyof`**, and getting this wrong is silent: a filter
     * with three conditions and no attribute matches a card satisfying one of
     * them, and a server reading it as a conjunction would answer a client's
     * search with a fraction of the cards it asked for.
     */
    public function testTheDefaultIsAnyOf(): void
    {
        self::assertSame(Test::AnyOf, Test::of(null));
    }

    public function testReadsBothValues(): void
    {
        self::assertSame(Test::AnyOf, Test::of('anyof'));
        self::assertSame(Test::AllOf, Test::of('allof'));
    }

    /**
     * **An enumerated attribute in XML is matched as it is written.** The DTD
     * spells both values in lower case, so `ANYOF` is not one of them.
     */
    public function testAnotherSpellingIsNoValue(): void
    {
        $this->expectException(BadRequest::class);

        Test::of('ANYOF');
    }

    /**
     * The DTD admits two values, and this server refuses a third rather than
     * guessing at it: guessing `anyof` for a client that meant `allof` answers
     * with cards it did not ask for.
     */
    public function testRefusesWhatTheDtdDoesNotAdmit(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('noneof');

        Test::of('noneof');
    }

    public function testAnyOfWantsOne(): void
    {
        self::assertTrue(Test::AnyOf->holds([false, true, false]));
        self::assertFalse(Test::AnyOf->holds([false, false]));
    }

    public function testAllOfWantsEveryOne(): void
    {
        self::assertTrue(Test::AllOf->holds([true, true]));
        self::assertFalse(Test::AllOf->holds([true, false]));
    }

    /**
     * **Over nothing the two part company**, and neither answer is a choice:
     * `allof` holds because nothing failed, `anyof` does not because nothing
     * matched.
     */
    public function testOverNothingTheyDisagree(): void
    {
        self::assertTrue(Test::AllOf->holds([]));
        self::assertFalse(Test::AnyOf->holds([]));
    }
}
