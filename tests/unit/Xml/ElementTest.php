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

namespace DavServices\Tests\Unit\Xml;

use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the two questions every body in this protocol asks.
 *
 * **Reading a request body is the same two questions over and over:** which
 * child is the `DAV:prop`, and what does it name? `PROPFIND` asked them
 * privately, and then `DAV:principal-property-search` asked them again — a
 * report that carried its own copy would be a second answer to "what counts
 * as the `prop` element", and the day the two disagreed a client would be
 * told its body meant something it did not.
 *
 * The rest of {@see Element} is exercised where it is built, in
 * `ReaderTest`; these two are asked of an element that is already there.
 */
#[CoversClass(Element::class)]
final class ElementTest extends TestCase
{
    public function testFindsTheChildOfAName(): void
    {
        $prop = new Element('{DAV:}prop');

        self::assertSame($prop, $this->propfind($prop)->child('{DAV:}prop'));
    }

    /**
     * A child that is not there is null rather than an exception: a body
     * that leaves out an optional element has asked a perfectly good
     * question, and most of these elements are optional.
     */
    public function testAChildThatIsNotThereIsNothing(): void
    {
        self::assertNull($this->propfind(new Element('{DAV:}prop'))->child('{DAV:}allprop'));
    }

    /**
     * **The first one wins, and predictably.** A body that names the same
     * element twice is a body the DTD does not describe; answering from the
     * first is a choice, and making the same choice every time is what keeps
     * two servers from reading one request two ways.
     */
    public function testTheFirstOfTwoIsTheOne(): void
    {
        $first = new Element('{DAV:}prop');
        $second = new Element('{DAV:}prop');
        $document = $this->propfind($first);

        $document->append($second);

        self::assertSame($first, $document->child('{DAV:}prop'));
    }

    /**
     * What a `DAV:prop` names, which is the list of properties a client asked
     * for — in the order it asked, because `DAV:principal-search-property-set`
     * and the reports that answer lists keep the order they were given.
     */
    public function testNamesWhatItsChildrenAreCalled(): void
    {
        $prop = new Element('{DAV:}prop');

        $prop->append(new Element('{DAV:}displayname'));
        $prop->append(new Element('{https://dav.services/test}colour'));

        self::assertSame(['{DAV:}displayname', '{https://dav.services/test}colour'], $prop->childNames());
    }

    public function testAnElementWithNoChildrenNamesNothing(): void
    {
        self::assertSame([], (new Element('{DAV:}prop'))->childNames());
    }

    private function propfind(Element $child): Element
    {
        $document = new Element('{DAV:}propfind');

        $document->append($child);

        return $document;
    }
}
