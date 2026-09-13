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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\IPropertyStorageBackend;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\TestCase;

/**
 * What every property storage has to do, whatever it keeps its properties in.
 *
 * Written once and run against each implementation, because a backend that is
 * exchangeable (R-PROP-02) is only exchangeable if they all behave the same.
 * An application that swaps one for another and finds its properties gone
 * would have been better off with no choice at all.
 *
 * The two rules that are easiest to get wrong are both in here. **A value must
 * come back exactly as it went in** (R-PROP-03): dead properties hold whatever
 * XML a client invented, and a storage that flattens it to text loses data it
 * was trusted with. And **a path that merely begins the same way is a
 * different path**: `alice2` does not lie below `alice`, and a storage that
 * thinks otherwise deletes somebody else's properties.
 */
abstract class PropertyStorageContract extends TestCase
{
    public function testKnowsNothingAboutAPathNobodyHasWrittenTo(): void
    {
        $storage = $this->storage();

        self::assertSame([], $storage->propertyNames('calendars/work.ics'));
        self::assertSame([], $storage->properties('calendars/work.ics', ['{DAV:}displayname']));
    }

    public function testKeepsAValueAndHandsItBack(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);

        self::assertSame(['{DAV:}displayname' => 'Work'], $storage->properties('calendars/work.ics', ['{DAV:}displayname']));
    }

    public function testKnowsWhichPropertiesItKeeps(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', [
            '{DAV:}displayname' => 'Work',
            '{http://apple.com/ns/ical/}calendar-color' => '#711A76',
        ]);

        self::assertSame(
            ['{DAV:}displayname', '{http://apple.com/ns/ical/}calendar-color'],
            $storage->propertyNames('calendars/work.ics'),
        );
    }

    /**
     * R-PROP-03: a dead property holds whatever XML a client invented, and it
     * comes back as it went in — foreign namespaces, nested elements,
     * attributes and all. A storage that flattened it to text would lose data
     * it was trusted with, and the client would never be told.
     */
    public function testAValueOfXmlComesBackAsItWentIn(): void
    {
        $storage = $this->storage();
        $value = new Element('{DAV:}owner');
        $principal = new Element('{http://example.com/ns}principal', ['id' => '42']);

        $principal->appendText('Alice');
        $value->append($principal);

        $storage->patchProperties('calendars/work.ics', ['{DAV:}owner' => $value]);

        $kept = $storage->properties('calendars/work.ics', ['{DAV:}owner'])['{DAV:}owner'] ?? null;

        self::assertInstanceOf(Element::class, $kept);
        self::assertSame($this->written($value), $this->written($kept));
    }

    public function testAnswersOnlyWhatItWasAskedFor(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice']);

        self::assertSame(['{DAV:}owner' => 'Alice'], $storage->properties('x', ['{DAV:}owner']));
    }

    /**
     * All of them, not the first of them: a `PROPFIND` asks for what it needs
     * in one go, and a storage that answered one at a time would turn a
     * listing into a query per property per member.
     */
    public function testAnswersEveryPropertyThatWasAskedFor(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice']);

        self::assertSame(
            ['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice'],
            $storage->properties('x', ['{DAV:}displayname', '{DAV:}owner']),
        );
    }

    public function testLeavesOutWhatItDoesNotKeep(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work']);

        self::assertSame(['{DAV:}displayname' => 'Work'], $storage->properties('x', ['{DAV:}displayname', '{DAV:}owner']));
    }

    public function testNullRemovesAProperty(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work']);
        $storage->patchProperties('x', ['{DAV:}displayname' => null]);

        self::assertSame([], $storage->propertyNames('x'));
    }

    /**
     * A `PROPPATCH` may remove a property a client believes is there and this
     * storage never had. That is the client being out of date, not an error.
     */
    public function testRemovingOneThatWasNeverThereIsNoError(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => null]);

        self::assertSame([], $storage->propertyNames('x'));
    }

    public function testChangesSeveralPropertiesAtOnce(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice']);
        $storage->patchProperties('x', ['{DAV:}displayname' => 'The new name', '{DAV:}owner' => null]);

        self::assertSame(['{DAV:}displayname' => 'The new name'], $storage->properties('x', ['{DAV:}displayname', '{DAV:}owner']));
    }

    /**
     * R-PROP-04: what a `DELETE` removed keeps no properties behind it. They
     * would otherwise come back with the next resource of that name, which is
     * the sort of surprise nobody has a way of explaining.
     */
    public function testForgettingAPathLeavesNothingOfIt(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('x', ['{DAV:}displayname' => 'Work']);
        $storage->forget('x');

        self::assertSame([], $storage->propertyNames('x'));
    }

    /**
     * A collection goes with everything below it, so its properties do too.
     */
    public function testForgettingACollectionLeavesNothingBelowIt(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/alice/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->forget('calendars');

        self::assertSame([], $storage->propertyNames('calendars/alice/work.ics'));
    }

    /**
     * The slash matters: `alice2` does not lie below `alice`, and a storage
     * that compares prefixes without it deletes somebody else's properties.
     */
    public function testForgettingDoesNotReachAPathThatMerelyBeginsTheSameWay(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/alice2/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->forget('calendars/alice');

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('calendars/alice2/work.ics'));
    }

    public function testForgettingTheRootLeavesNothingAtAll(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->patchProperties('addressbooks/friends.vcf', ['{DAV:}displayname' => 'Friends']);
        $storage->forget('');

        self::assertSame([], $storage->propertyNames('calendars/work.ics'));
        self::assertSame([], $storage->propertyNames('addressbooks/friends.vcf'), 'Only the first path was forgotten.');
    }

    /**
     * R-PROP-04: a `MOVE` carries the properties along. A calendar that
     * arrived at its new address without its colour and its name would look to
     * the client like a different calendar.
     */
    public function testMovingCarriesThePropertiesAlong(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->moveTo('calendars/work.ics', 'archive/work.ics');

        self::assertSame(['{DAV:}displayname' => 'Work'], $storage->properties('archive/work.ics', ['{DAV:}displayname']));
        self::assertSame([], $storage->propertyNames('calendars/work.ics'));
    }

    public function testMovingACollectionCarriesWhatLiesBelowIt(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/alice/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->moveTo('calendars/alice', 'archive/alice');

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive/alice/work.ics'));
        self::assertSame([], $storage->propertyNames('calendars/alice/work.ics'));
    }

    public function testMovingDoesNotReachAPathThatMerelyBeginsTheSameWay(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/alice2/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->moveTo('calendars/alice', 'archive/alice');

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('calendars/alice2/work.ics'));
    }

    /**
     * The storage under test, empty.
     */
    abstract protected function storage(): IPropertyStorageBackend;

    private function written(Element $element): string
    {
        return (new Writer())->write($element);
    }
}
