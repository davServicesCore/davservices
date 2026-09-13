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

use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-PROP-05 (contributions from the node, from plugins
 * and from property storage are merged in a defined order) and R-DAV-04 (a
 * property nobody has gets its own `404` block; one that may not be read gets
 * `403`).
 *
 * This is where a `PROPFIND` answer is assembled for one resource, and the
 * rule that makes the order mean anything is that **the first answer for a
 * property stands**. Without it the last contributor loaded would win, which
 * is to say the order of `require` statements in somebody's application would
 * decide what a client is told.
 */
#[CoversClass(PropFindResult::class)]
#[CoversClass(PropFindForm::class)]
final class PropFindResultTest extends TestCase
{
    public function testKeepsWhatItIsAbout(): void
    {
        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}getetag']);

        self::assertSame('calendars/work.ics', $result->path());
        self::assertSame(PropFindForm::Named, $result->form());
        self::assertSame(['{DAV:}getetag'], $result->names());
    }

    public function testWantsWhatWasNamed(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}getetag']);

        self::assertTrue($result->wants('{DAV:}getetag'));
        self::assertFalse($result->wants('{DAV:}displayname'), 'A property nobody asked for was collected.');
    }

    /**
     * `allprop` and `propname` ask about everything there is, so a contributor
     * offering a property of its own invention is answering the request.
     */
    public function testWantsAnythingWhereEverythingWasAsked(): void
    {
        self::assertTrue((new PropFindResult('x', PropFindForm::Everything))->wants('{DAV:}displayname'));
        self::assertTrue((new PropFindResult('x', PropFindForm::NamesOnly))->wants('{DAV:}displayname'));
    }

    public function testStopsWantingOneThatHasBeenAnswered(): void
    {
        $result = new PropFindResult('x', PropFindForm::Everything);
        $result->set('{DAV:}displayname', 'Work');

        self::assertFalse($result->wants('{DAV:}displayname'));
    }

    /**
     * R-PROP-05: the first answer stands, so that priority decides and not the
     * order things happened to be registered in. A plugin that has to have the
     * last word — access control refusing a property the node would gladly
     * hand over — takes the first one instead.
     */
    public function testTheFirstAnswerStands(): void
    {
        $result = new PropFindResult('x', PropFindForm::Everything);

        $result->set('{DAV:}displayname', 'The plugin');
        $result->set('{DAV:}displayname', 'The node');

        self::assertSame(['{DAV:}displayname' => 'The plugin'], $result->byStatus()[200] ?? []);
    }

    /**
     * A property that was answered with a refusal is answered: nothing behind
     * it may quietly supply a value the client was not to see.
     */
    public function testARefusalIsAnAnswerToo(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}owner']);

        $result->set('{DAV:}owner', null, 403);
        $result->set('{DAV:}owner', 'Alice');

        self::assertFalse($result->wants('{DAV:}owner'));
        self::assertSame(['{DAV:}owner' => null], $result->byStatus()[403] ?? []);
    }

    /**
     * RFC 4918 §14.22: properties that fared differently belong to different
     * blocks. One block with a mixed result tells a client nothing.
     */
    public function testGroupsTheAnswersByTheirStatus(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}displayname', '{DAV:}owner']);

        $result->set('{DAV:}displayname', 'Work');
        $result->set('{DAV:}owner', null, 403);

        self::assertSame([200, 403], array_keys($result->byStatus()));
    }

    /**
     * R-DAV-04: a property that was asked for and that nobody has is reported
     * as missing rather than left out. A client that asked for five and got
     * three has no way of telling which two were dropped.
     */
    public function testWhatNobodyAnsweredIsReportedAsMissing(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}displayname', '{DAV:}owner']);

        $result->set('{DAV:}displayname', 'Work');

        self::assertSame(['{DAV:}owner' => null], $result->byStatus()[404] ?? []);
    }

    /**
     * Nothing was named under `allprop`, so nothing can be missing: a server
     * that invented a `404` block there would be reporting on properties the
     * client never mentioned.
     */
    public function testNothingIsMissingWhereNothingWasNamed(): void
    {
        $result = new PropFindResult('x', PropFindForm::Everything);
        $result->set('{DAV:}displayname', 'Work');

        self::assertArrayNotHasKey(404, $result->byStatus());
    }

    /**
     * RFC 4918 §9.1: `DAV:include` names properties to be returned besides
     * those of `allprop`. Naming one is asking for it, so one that nobody has
     * is missing exactly as under `prop`.
     */
    public function testAnIncludedNameNobodyHasIsMissingToo(): void
    {
        $result = new PropFindResult('x', PropFindForm::Everything, ['{DAV:}quota-used-bytes']);

        self::assertSame(['{DAV:}quota-used-bytes' => null], $result->byStatus()[404] ?? []);
    }

    /**
     * RFC 4918 §9.1: `propname` asks which properties there are, not what they
     * hold. Sending the values would make the cheap question the expensive
     * one, and clients use it precisely to avoid that.
     */
    public function testNamesOnlyKeepsTheNamesAndDropsTheValues(): void
    {
        $result = new PropFindResult('x', PropFindForm::NamesOnly);

        $result->set('{DAV:}displayname', 'Work');
        $result->set('{DAV:}getetag', new Element('{DAV:}getetag'));

        self::assertSame(['{DAV:}displayname' => null, '{DAV:}getetag' => null], $result->byStatus()[200] ?? []);
    }

    /**
     * RFC 4918 §14.24: a `response` carries at least one `propstat`. A
     * resource with nothing to say says so with an empty block rather than
     * with a document a strict client will refuse to read.
     */
    public function testAResourceWithNothingToSayStillSaysSo(): void
    {
        self::assertSame([200 => []], (new PropFindResult('x', PropFindForm::Everything))->byStatus());
    }

    /**
     * What the node is asked for: the names still open. Asking it again for
     * what a plugin has already answered is a query the backend runs for
     * nothing.
     */
    public function testTellsWhatIsStillOpen(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}displayname', '{DAV:}owner']);

        $result->set('{DAV:}displayname', 'Work');

        self::assertSame(['{DAV:}owner'], $result->stillWanted());
    }

    /**
     * All of them, not the first of them: a node asked for one property at a
     * time is a backend asked once per property.
     */
    public function testTellsAboutEveryOpenNameAtOnce(): void
    {
        $result = new PropFindResult('x', PropFindForm::Named, ['{DAV:}displayname', '{DAV:}owner']);

        self::assertSame(['{DAV:}displayname', '{DAV:}owner'], $result->stillWanted());
    }

    /**
     * Under `allprop` the names are the extras of `DAV:include`, and under
     * `propname` there are none: a node cannot be asked for the values of
     * properties nobody has named.
     */
    public function testTellsWhatIsStillOpenUnderTheOtherForms(): void
    {
        self::assertSame(['{DAV:}quota-used-bytes'], (new PropFindResult('x', PropFindForm::Everything, ['{DAV:}quota-used-bytes']))->stillWanted());
        self::assertSame([], (new PropFindResult('x', PropFindForm::NamesOnly))->stillWanted());
    }

    public function testKeepsAnElementValueAsItIs(): void
    {
        $value = new Element('{DAV:}resourcetype');
        $result = new PropFindResult('x', PropFindForm::Everything);

        $result->set('{DAV:}resourcetype', $value);

        self::assertSame(['{DAV:}resourcetype' => $value], $result->byStatus()[200] ?? []);
    }
}
