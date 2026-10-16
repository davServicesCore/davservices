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

use DavServices\Dav\PropPatchResult;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-05 (a `PROPPATCH` is atomic: on failure every
 * other property gets `424` and nothing is stored) and RFC 4918 §9.2.
 *
 * This is where the changes of one request are gathered and where the rule
 * that makes them atomic lives: **one failure fails them all**. The property
 * that was refused keeps its own status, and every other one gets `424 Failed
 * Dependency` — which says the thing a client needs to hear, that there is
 * nothing wrong with *that* property and no point in retrying it alone.
 *
 * The values never come back (RFC 4918 §9.2): the client sent them, and a
 * server that echoed them would double the size of every answer to say
 * nothing.
 */
#[CoversClass(PropPatchResult::class)]
final class PropPatchResultTest extends TestCase
{
    public function testKeepsWhatItIsAbout(): void
    {
        $result = new PropPatchResult('calendars/work.ics', ['{DAV:}displayname' => 'Work']);

        self::assertSame('calendars/work.ics', $result->path());
        self::assertSame(['{DAV:}displayname' => 'Work'], $result->mutations());
    }

    public function testEverythingIsOpenUntilSomebodyTakesIt(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        self::assertSame(['{DAV:}displayname' => 'Work', '{DAV:}owner' => null], $result->open());
    }

    public function testAPropertySomebodyWillWriteIsNoLongerOpen(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        $result->willWrite('{DAV:}displayname', static function (): void {
        });

        self::assertSame(['{DAV:}owner' => null], $result->open());
    }

    public function testAPropertyThatHasAStatusIsNoLongerOpen(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);

        $result->set('{DAV:}displayname', 403);

        self::assertSame([], $result->open());
    }

    /**
     * The first to answer owns the property, as in a `PROPFIND`: the order of
     * the listeners decides, and not the order they happened to be loaded in.
     */
    public function testTheFirstAnswerStands(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);

        $result->set('{DAV:}displayname', 403);
        $result->set('{DAV:}displayname', 200);

        self::assertSame([403 => ['{DAV:}displayname' => null]], $result->byStatus());
    }

    /**
     * **A refusal may name the condition it broke**, and RFC 4918 §16 makes
     * that a MUST rather than a nicety: "In a 207 Multi-Status response, the
     * XML element MUST appear inside an 'error' element in the appropriate
     * 'propstat' or 'response' element depending on whether the condition
     * applies to one or more properties or to the resource as a whole."
     *
     * A property refused for a reason the memos have a name for is the first
     * of those two cases, so the name is kept here beside the status and the
     * response puts it where §16 says.
     */
    public function testARefusalMayNameTheConditionItBroke(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}getetag' => 'Work', '{DAV:}displayname' => 'Work']);

        $result->set('{DAV:}getetag', 403, '{DAV:}cannot-modify-protected-property');
        $result->set('{DAV:}displayname', 403);

        self::assertSame(['{DAV:}getetag' => '{DAV:}cannot-modify-protected-property'], $result->errors());
    }

    /**
     * And the first answer's condition stands with its status: whoever
     * answered first owns the property, reason and all.
     */
    public function testTheFirstConditionStandsToo(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}getetag' => 'Work']);

        $result->set('{DAV:}getetag', 403, '{DAV:}cannot-modify-protected-property');
        $result->set('{DAV:}getetag', 409, '{DAV:}preserved-live-properties');

        self::assertSame(['{DAV:}getetag' => '{DAV:}cannot-modify-protected-property'], $result->errors());
    }

    /**
     * **A condition that carries detail is kept whole.** RFC 4918 §16: "The
     * 'error' element MAY contain child elements with specific error
     * information" — so an element arrives as an element rather than being
     * flattened into its own name.
     */
    public function testAConditionThatCarriesDetailIsKeptWhole(): void
    {
        $condition = new Element('{DAV:}no-uid-conflict');
        $result = new PropPatchResult('x', ['{DAV:}getetag' => 'Work']);

        $result->set('{DAV:}getetag', 409, $condition);

        self::assertSame(['{DAV:}getetag' => $condition], $result->errors());
    }

    public function testNothingHasFailedWhileEverythingIsGoingWell(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);

        self::assertFalse($result->hasFailure());

        $result->set('{DAV:}displayname', 200);

        self::assertFalse($result->hasFailure());
    }

    public function testARefusalIsAFailure(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);

        $result->set('{DAV:}displayname', 403);

        self::assertTrue($result->hasFailure());
    }

    /**
     * The range has two edges, and a status on either side of one decides
     * whether the whole request is rolled up as failed. A `199` is no success
     * however it is spelt, and a `299` — whatever a backend would mean by it
     * — is one.
     */
    #[DataProvider('statusesAtTheEdges')]
    public function testOnlyATwoHundredCountsAsHavingGoneWell(int $status, bool $failed): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);

        $result->set('{DAV:}displayname', $status);

        self::assertSame($failed, $result->hasFailure());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statusesAtTheEdges(): iterable
    {
        yield 'just below the range' => [199, true];
        yield 'the bottom of the range' => [200, false];
        yield 'the top of the range' => [299, false];
        yield 'just above the range' => [300, true];
    }

    public function testWhatWentWellIsReportedAsOneBlock(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        $result->set('{DAV:}displayname', 200);
        $result->set('{DAV:}owner', 200);

        self::assertSame([200 => ['{DAV:}displayname' => null, '{DAV:}owner' => null]], $result->byStatus());
    }

    /**
     * R-DAV-05: the one that was refused keeps its own status, and every other
     * one gets `424` — there is nothing wrong with those, and a client told
     * `403` for them would go and change something that was never the problem.
     *
     * The blocks come in the order the request named the properties in, which
     * is the order the client already has them in.
     */
    public function testOneFailureFailsThemAll(): void
    {
        $result = new PropPatchResult('x', [
            '{DAV:}displayname' => 'Work',
            '{DAV:}owner' => null,
            '{DAV:}getetag' => '"abc"',
        ]);

        $result->set('{DAV:}owner', 403);

        self::assertSame([
            424 => ['{DAV:}displayname' => null, '{DAV:}getetag' => null],
            403 => ['{DAV:}owner' => null],
        ], $result->byStatus());
    }

    /**
     * Including the ones that had already gone well. A backend that is atomic
     * reports a status for every property it was handed — `200` for the ones
     * it would have changed — and none of those changes happened, so none of
     * them may be reported as though they had.
     */
    public function testWhatWouldHaveGoneWellIsReportedAsFailedDependencyToo(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        $result->set('{DAV:}displayname', 200);
        $result->set('{DAV:}owner', 403);

        self::assertSame([
            424 => ['{DAV:}displayname' => null],
            403 => ['{DAV:}owner' => null],
        ], $result->byStatus());
    }

    /**
     * Including the ones nobody ever got round to: a request that was stopped
     * before it began still has to account for every property in it.
     */
    public function testWhatWasNeverAttemptedIsAccountedForToo(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        $result->set('{DAV:}displayname', 409);

        self::assertSame(['{DAV:}owner' => null], $result->byStatus()[424] ?? []);
    }

    /**
     * RFC 4918 §9.2: the `DAV:prop` of the answer carries the names alone. The
     * client sent the values; echoing them back doubles every answer to say
     * nothing.
     */
    public function testTheValuesNeverComeBack(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => new Element('{DAV:}displayname')]);

        $result->set('{DAV:}displayname', 200);

        self::assertSame([200 => ['{DAV:}displayname' => null]], $result->byStatus());
    }

    /**
     * The writers are run by the method, not here: this only knows who is to
     * run, so that the decision to write at all can be taken before any of
     * them does.
     */
    public function testHandsOverTheWritersInTheOrderTheyWereRegistered(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => null]);

        $result->willWrite('{DAV:}owner', static function (): void {
        });
        $result->willWrite('{DAV:}displayname', static function (): void {
        });

        self::assertSame(['{DAV:}owner', '{DAV:}displayname'], array_keys($result->writers()));
    }

    public function testTheFirstWriterOwnsTheProperty(): void
    {
        $result = new PropPatchResult('x', ['{DAV:}displayname' => 'Work']);
        $wrote = [];

        $result->willWrite('{DAV:}displayname', static function () use (&$wrote): void {
            $wrote[] = 'the first';
        });
        $result->willWrite('{DAV:}displayname', static function () use (&$wrote): void {
            $wrote[] = 'the second';
        });

        foreach ($result->writers() as $writer) {
            $writer('Work');
        }

        self::assertSame(['the first'], $wrote);
    }
}
