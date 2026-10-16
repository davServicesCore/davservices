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

namespace DavServices\Tests\Unit\CardDav;

use DavServices\CardDav\AddressObject;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\PlainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what an address object is. RFC 6352 §5.1.
 *
 * > Address object resources contained in address book collections MUST contain
 * > a single vCard component only.
 *
 * **One sentence, and both reports lean on it.** A `addressbook-query` searches
 * address objects and a `addressbook-multiget` retrieves them, so each needs
 * the same answer to "is this one, and what card is in it" — and a second copy
 * of the answer would be a second chance for the two reports to disagree about
 * what a client's address book holds.
 */
#[CoversClass(AddressObject::class)]
final class AddressObjectTest extends TestCase
{
    private const SIMON = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:simon\r\nFN:Simon Perreault\r\nEND:VCARD\r\n";

    public function testAFileHoldingOneVcardIsOne(): void
    {
        $card = AddressObject::of(new MemoryFile('simon.vcf', self::SIMON));

        self::assertNotNull($card);
        self::assertSame('Simon Perreault', $card->property('FN')?->value());
    }

    /**
     * **The same question asked of bytes.** §6.3.2.1 asks it of "the resource
     * submitted in the PUT request", which is a body on its way in and has no
     * node yet — so the rule is reachable without one.
     */
    public function testTheSameQuestionIsAskedOfBytes(): void
    {
        self::assertSame('Simon Perreault', AddressObject::in(self::SIMON)?->property('FN')?->value());
        self::assertNull(AddressObject::in('This is a note, not a card.'));
        self::assertNull(AddressObject::in(self::SIMON . self::SIMON));
        self::assertNull(AddressObject::in(''));
    }
    /**
     * "a single vCard component **only**" — so two of them is not one of them.
     */
    public function testAFileHoldingTwoOfThemIsNotOne(): void
    {
        self::assertNull(AddressObject::of(new MemoryFile('both.vcf', self::SIMON . self::SIMON)));
    }

    /**
     * An empty file reads to no object at all, which is no more an address
     * object than two of them are.
     */
    public function testAFileHoldingNoneIsNotOne(): void
    {
        self::assertNull(AddressObject::of(new MemoryFile('empty.vcf')));
    }

    /**
     * Text that is no vCard is passed over rather than reported: a report
     * answers about address objects, and this is not one. Failing a client's
     * whole request over data it cannot see and could not correct would be the
     * worse answer.
     */
    public function testTextThatIsNoVcardIsNotOne(): void
    {
        self::assertNull(AddressObject::of(new MemoryFile('note.txt', 'This is a note, not a card.')));
    }

    /**
     * **A component of another kind is not one either.** §5.1 says "vCard", and
     * this library advertises `text/vcard` and nothing else through
     * `CARDDAV:supported-address-data` — a calendar that wandered into an
     * address book is not address data because it is in the wrong place.
     */
    public function testACalendarIsNotOne(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\nEND:VCALENDAR\r\n";

        self::assertNull(AddressObject::of(new MemoryFile('calendar.ics', $calendar)));
    }

    /**
     * **A card written in lower case is still a card**, and RFC 6350 says so
     * about this very comparison. §6.1.1: "The content entity MUST begin with
     * the BEGIN property with a value of 'VCARD'. **The value is
     * case-insensitive.**" §6.1.2 says the same of `END`, and §3.3 says it of
     * every name: "Property names and parameter names are case-insensitive
     * (e.g., the property name 'fn' is the same as 'FN' and 'Fn')."
     *
     * {@see \DavServices\VObject\Component} already looks names up that way,
     * and {@see \DavServices\CardDav\AddressData} already lowers the media
     * type at the door. Only this comparison was exact — so a quiet client's
     * card was refused as no card at all, which on a `PUT` is the
     * `CARDDAV:valid-address-data` precondition of RFC 6352 §6.3.2.1 and the
     * one refusal a client cannot argue with.
     *
     * §3.3 closes with "it is RECOMMENDED that property and parameter names be
     * upper-case on output" — a recommendation about writing, which says
     * nothing about what a server may refuse to read.
     */
    public function testACardWrittenInLowerCaseIsStillACard(): void
    {
        $quiet = "begin:vcard\r\nversion:4.0\r\nuid:simon\r\nfn:Simon Perreault\r\nend:vcard\r\n";

        self::assertSame('Simon Perreault', AddressObject::in($quiet)?->property('FN')?->value());
        self::assertNotNull(AddressObject::of(new MemoryFile('simon.vcf', $quiet)));
    }

    /**
     * A collection holds address objects; it is not one.
     */
    public function testACollectionIsNotOne(): void
    {
        self::assertNull(AddressObject::of(new MemoryCollection('photos')));
    }

    /**
     * **And a member that is no file at all has nothing to read.** `INode` is
     * the base of the tree and a collection may hold one — a principal mounted
     * inside one is the usual case — so a walk that took every non-collection
     * for a card would fail on a tree that is perfectly legal.
     */
    public function testSomethingThatIsNoFileIsNotOne(): void
    {
        self::assertNull(AddressObject::of(new PlainNode('a-principal')));
    }
}
