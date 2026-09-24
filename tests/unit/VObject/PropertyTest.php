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

namespace DavServices\Tests\Unit\VObject;

use DavServices\VObject\ContentLine;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.5 and RFC 6350 §3.3, and R-VOBJ-07.
 *
 * **A property is a content line that has been understood.** RFC 5545 §3.5:
 * "A property is the definition of an individual attribute describing a
 * calendar object or a calendar component. A property takes the form defined
 * by the `contentline` notation defined in Section 3.1."
 *
 * So this is the same four things {@see ContentLine} reads — group, name,
 * parameters, value — with the parameters turned into objects and a place for
 * the rules that belong to a property rather than to a line.
 *
 * ## What it does not do yet
 *
 * **The value is still raw text.** `\n`, `\,` and `\;` are the escaping of
 * the `TEXT` value type alone (RFC 5545 §3.3.11), and a `DATE-TIME`, a `URI`
 * or an `INTEGER` has none — so undoing it before the type is known would
 * corrupt everything that is not text. The typed values come in P4-03, and
 * they will read this.
 *
 * ## Looking a parameter up
 *
 * **RFC 5545 §3.5 is explicit**: "Property names, parameter names, and
 * enumerated parameter values are case-insensitive. For example, the property
 * name `DUE` is the same as `due` and `Due`,
 * `DTSTART;TZID=America/New_York:19980714T120000` is the same as
 * `DtStart;TzID=America/New_York:19980714T120000`." That example is the test.
 */
#[CoversClass(Property::class)]
final class PropertyTest extends TestCase
{
    /**
     * The four things a property is.
     */
    public function testHoldsWhatAContentLineSaid(): void
    {
        $property = new Property('SUMMARY', 'Lunch with Ada');

        self::assertSame('SUMMARY', $property->name());
        self::assertSame('Lunch with Ada', $property->value());
        self::assertSame([], $property->parameters());
        self::assertNull($property->group());
    }

    /**
     * **Built from a line the lexer read**, which is how every property that
     * came from a file is made. The group comes along: RFC 6350 §3.3 puts it
     * before the name, and an address book that lost it would file three
     * entries where somebody had grouped one.
     */
    public function testIsBuiltFromAContentLine(): void
    {
        $property = Property::from(ContentLine::of('item1.EMAIL;TYPE=work:ada@example.com'));

        self::assertSame('item1', $property->group());
        self::assertSame('EMAIL', $property->name());
        self::assertSame('ada@example.com', $property->value());
        self::assertSame(['work'], $property->parameter('TYPE')?->values());
    }

    /**
     * A line's parameters become parameters, in the order they were written.
     */
    public function testTheParametersOfALineBecomeParameters(): void
    {
        $property = Property::from(ContentLine::of('ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:ada@example.com'));

        self::assertSame(['ROLE', 'PARTSTAT'], array_map(
            static fn (Parameter $parameter): string => $parameter->name(),
            $property->parameters(),
        ));
    }

    /**
     * **RFC 5545 §3.5's own example, word for word:**
     * "`DTSTART;TZID=America/New_York:19980714T120000` is the same as
     * `DtStart;TzID=America/New_York:19980714T120000`."
     */
    public function testAParameterIsFoundWhateverItsSpelling(): void
    {
        $property = Property::from(ContentLine::of('DtStart;TzID=America/New_York:19980714T120000'));

        self::assertSame('America/New_York', $property->parameter('TZID')?->value());
    }

    /**
     * A parameter that is not there is null rather than an empty one: there
     * is a difference between `VALUE=` and no `VALUE` at all, and a caller
     * has to be able to see it.
     */
    public function testAParameterThatIsNotThereIsNull(): void
    {
        self::assertNull((new Property('SUMMARY', 'x'))->parameter('TZID'));
    }

    /**
     * **The same parameter twice is kept twice.** RFC 6350 §5 has parameters
     * that may appear more than once, and `parameter()` answers with the
     * first — which is what a caller asking for "the" time zone means.
     */
    public function testTheSameParameterTwiceIsKeptTwice(): void
    {
        $property = Property::from(ContentLine::of('TEL;TYPE=work;TYPE=voice:+44 20 7123 4567'));

        self::assertCount(1, $property->parameters(), 'the line gathers the values under one name');
        self::assertSame(['work', 'voice'], $property->parameter('TYPE')?->values());
    }

    /**
     * **R-VOBJ-07: ergonomic access.** `$property['TZID']` is what a caller
     * writes, and it answers the same thing `parameter()` does.
     */
    public function testAParameterIsReachedByArrayAccess(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;TZID=Europe/London:19980714T120000'));
        $parameter = $property['TZID'];

        self::assertInstanceOf(Parameter::class, $parameter);
        self::assertSame('Europe/London', $parameter->value());
    }

    /**
     * And `isset()` answers without fetching it, which is what a caller
     * checking for a parameter actually wants to say.
     */
    public function testWhetherAParameterIsThereIsAskedWithIsset(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;TZID=Europe/London:19980714T120000'));

        self::assertTrue(isset($property['tzid']));
        self::assertFalse(isset($property['VALUE']));
    }

    /**
     * **A parameter is set through the same door**, and setting one that is
     * already there replaces it rather than adding a second — which is what
     * `$property['TZID'] = …` plainly means.
     */
    public function testSettingAParameterReplacesTheOneThatWasThere(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;TZID=Europe/London:19980714T120000'));

        $property['tzid'] = new Parameter('TZID', ['America/New_York']);

        self::assertSame('America/New_York', $property->parameter('TZID')?->value());
        self::assertCount(1, $property->parameters());
    }

    /**
     * And a parameter that was not there is added.
     */
    public function testSettingAParameterThatWasNotThereAddsIt(): void
    {
        $property = new Property('DTSTART', '19980714T120000');

        $property['VALUE'] = new Parameter('VALUE', ['DATE-TIME']);

        self::assertSame(['DATE-TIME'], $property->parameter('VALUE')?->values());
    }

    /**
     * **Setting one puts it where the old one stood.** Nothing makes the
     * order of parameters mean anything, and it is kept all the same, so a
     * changed property still reads as the line somebody wrote.
     */
    public function testSettingAParameterKeepsItsPlace(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;VALUE=DATE-TIME;TZID=Europe/London:19980714T120000'));

        $property['VALUE'] = new Parameter('VALUE', ['DATE']);

        self::assertSame(['VALUE', 'TZID'], array_map(
            static fn (Parameter $parameter): string => $parameter->name(),
            $property->parameters(),
        ));
    }

    /**
     * `$property[] = $parameter` adds one without replacing anything, which
     * is what a property carrying `TYPE` twice needs.
     */
    public function testTheEmptyBracketsAppend(): void
    {
        $property = new Property('TEL', '+44 20 7123 4567');

        $property[] = new Parameter('TYPE', ['work']);
        $property[] = new Parameter('TYPE', ['voice']);

        self::assertCount(2, $property->parameters());
    }

    /**
     * And `add()` is the same door written out, for callers who would rather
     * say what they mean than lean on the brackets.
     */
    public function testAParameterIsAddedByName(): void
    {
        $property = new Property('TEL', '+44 20 7123 4567');

        $property->add(new Parameter('TYPE', ['work']));

        self::assertSame(['work'], $property->parameter('TYPE')?->values());
    }

    /**
     * **Replacing one in the middle keeps what comes after it.** A reader
     * that stopped at the first parameter it was not looking for would drop
     * everything beyond — quietly, and only for properties carrying three or
     * more.
     */
    public function testReplacingOneInTheMiddleKeepsWhatFollows(): void
    {
        $property = Property::from(ContentLine::of('ATTENDEE;ROLE=CHAIR;PARTSTAT=NEEDS-ACTION;CN=Ada:mailto:ada@x'));

        $property['PARTSTAT'] = new Parameter('PARTSTAT', ['ACCEPTED']);

        self::assertSame(['ROLE', 'PARTSTAT', 'CN'], array_map(
            static fn (Parameter $parameter): string => $parameter->name(),
            $property->parameters(),
        ));
    }

    /**
     * `unset($property['TZID'])` takes it away, and a floating time is
     * exactly a `DTSTART` with no `TZID` (RFC 5545 §3.3.5) — so this is a
     * thing somebody really does.
     */
    public function testAParameterIsTakenAwayWithUnset(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;TZID=Europe/London:19980714T120000'));

        unset($property['TZID']);

        self::assertSame([], $property->parameters());
    }

    /**
     * And it takes away only that one: a `DTSTART` losing its time zone keeps
     * whatever else was written on it.
     */
    public function testTakingOneAwayLeavesTheOthers(): void
    {
        $property = Property::from(ContentLine::of('DTSTART;VALUE=DATE-TIME;TZID=Europe/London:19980714T120000'));

        unset($property['TZID']);

        self::assertSame(['VALUE'], array_map(
            static fn (Parameter $parameter): string => $parameter->name(),
            $property->parameters(),
        ));
    }

    /**
     * **Taking away one that was never there is not an error.** That is what
     * `unset()` means everywhere else in PHP, and a caller making sure a
     * property is floating should not have to look first.
     */
    public function testTakingAwayAParameterThatIsNotThereDoesNothing(): void
    {
        $property = new Property('DTSTART', '19980714T120000');

        unset($property['TZID']);

        self::assertSame([], $property->parameters());
    }

    /**
     * **The value can be changed**, because a calendar object is a document
     * people edit. Rebuilding the whole property to correct a summary would
     * make every edit a copy of everything around it.
     */
    public function testTheValueCanBeChanged(): void
    {
        $property = new Property('SUMMARY', 'Lunch');

        $property->setValue('Lunch with Ada');

        self::assertSame('Lunch with Ada', $property->value());
    }
}
