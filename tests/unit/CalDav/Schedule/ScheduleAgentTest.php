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

namespace DavServices\Tests\Unit\CalDav\Schedule;

use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Schedule\ScheduleAgent;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §7.1, the `SCHEDULE-AGENT` parameter. R-CAL-32,
 * P8-02c.
 *
 * **This parameter is what decides whether anybody is told at all**, so §3's
 * every table begins with it:
 *
 * > scheduleagentparam = "SCHEDULE-AGENT" "="
 * >                  ("SERVER"      ; The server handles scheduling
 * >                 / "CLIENT"      ; The client handles scheduling
 * >                 / "NONE"        ; No scheduling
 * >                 / x-name        ; Experimental type
 * >                 / iana-token)   ; Other IANA-registered type
 * > ;
 * > ; If the parameter is not present, its value defaults to SERVER.
 *
 * ## Two sentences do the work
 *
 * > In the absence of this parameter, the value "SERVER" MUST be used for the
 * > default behavior.
 *
 * So the ordinary invitation — an `ATTENDEE` with no parameter on it at all —
 * is the server's to deliver, and a reading that defaulted the other way would
 * be a server that schedules nothing until asked.
 *
 * > Servers and clients MUST treat x-name and iana-token values they do not
 * > recognize the same way as they would the "NONE" value.
 *
 * And that is the other direction: anything this server does not know is
 * nobody being told, rather than a guess at what was meant. **A value it does
 * not recognise is not the same as none at all** — the first says scheduling
 * was spoken for by something this server is not, the second says nothing was
 * said — which is why the two sentences cannot be folded into one.
 *
 * ## The spelling does not matter and the value is one value
 *
 * RFC 5545 §3.5: "Property names, parameter names, and enumerated parameter
 * values are case-insensitive." All three of §7.1's values are enumerated, so
 * `schedule-agent=client` says what `SCHEDULE-AGENT=CLIENT` says.
 *
 * A parameter carrying more than one value, or none, is read as `NONE` for the
 * sentence above: §7.1's grammar has exactly one value, so a list is not a
 * value this server recognises — and of the two directions to fail in, the one
 * where nobody is told is the one that can be put right by a client.
 */
#[CoversClass(ScheduleAgent::class)]
final class ScheduleAgentTest extends TestCase
{
    /**
     * **§7.1: "If the parameter is not present, its value defaults to
     * SERVER."** The ordinary invitation, and the case the whole memo is
     * about.
     */
    public function testAnAttendeeWithNoParameterIsTheServersToTell(): void
    {
        self::assertSame(ScheduleAgent::Server, $this->agentOn('ATTENDEE:mailto:cyrus@example.com'));
    }

    /**
     * **The three values of §7.1's grammar**, each read as itself.
     */
    #[DataProvider('theValues')]
    public function testEachValueOfTheGrammarIsRead(string $value, ScheduleAgent $expected): void
    {
        self::assertSame(
            $expected,
            $this->agentOn('ATTENDEE;SCHEDULE-AGENT=' . $value . ':mailto:cyrus@example.com'),
        );
    }

    /**
     * @return iterable<string, array{string, ScheduleAgent}>
     */
    public static function theValues(): iterable
    {
        yield 'the server handles scheduling' => ['SERVER', ScheduleAgent::Server];
        yield 'the client handles scheduling' => ['CLIENT', ScheduleAgent::Client];
        yield 'no scheduling' => ['NONE', ScheduleAgent::None];
    }

    /**
     * **RFC 5545 §3.5: an enumerated parameter value is case-insensitive.**
     * All three of §7.1's are enumerated, and a server that answered only to
     * the shouted spelling would deliver nothing for a client that writes
     * iCalendar in lower case.
     */
    #[DataProvider('theSpellings')]
    public function testTheSpellingOfTheParameterDoesNotMatter(
        string $parameter,
        ScheduleAgent $expected,
    ): void {
        self::assertSame($expected, $this->agentOn('ATTENDEE;' . $parameter . ':mailto:cyrus@example.com'));
    }

    /**
     * @return iterable<string, array{string, ScheduleAgent}>
     */
    public static function theSpellings(): iterable
    {
        yield 'the value in lower case' => ['SCHEDULE-AGENT=server', ScheduleAgent::Server];
        yield 'the value in mixed case' => ['SCHEDULE-AGENT=Client', ScheduleAgent::Client];
        yield 'the name in lower case' => ['schedule-agent=CLIENT', ScheduleAgent::Client];
        yield 'both in lower case' => ['schedule-agent=none', ScheduleAgent::None];
    }

    /**
     * **§7.1's MUST about everything else**: "Servers and clients MUST treat
     * x-name and iana-token values they do not recognize the same way as they
     * would the 'NONE' value."
     *
     * The empty and the doubled value are read the same way, and for the same
     * sentence: §7.1's grammar has one value, so neither is a value this
     * server recognises. **Of the two directions to fail in**, the one where
     * nobody is told is the one a client can put right; the other sends
     * somebody's invitation on the strength of a parameter nobody understood.
     */
    #[DataProvider('theOnesItDoesNotKnow')]
    public function testAValueItDoesNotRecogniseTellsNobody(string $parameter): void
    {
        self::assertSame(ScheduleAgent::None, $this->agentOn('ATTENDEE;' . $parameter . ':mailto:cyrus@example.com'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theOnesItDoesNotKnow(): iterable
    {
        yield 'an x-name' => ['SCHEDULE-AGENT=X-MAGIC'];
        yield 'an iana-token it has never heard of' => ['SCHEDULE-AGENT=ROBOT'];
        yield 'a value of nothing' => ['SCHEDULE-AGENT='];
        yield 'two values where the grammar has one' => ['SCHEDULE-AGENT=CLIENT,SERVER'];
        yield 'the same twice' => ['SCHEDULE-AGENT=SERVER,SERVER'];
    }

    /**
     * **§7.1: "This property parameter MAY be specified on 'ORGANIZER' or
     * 'ATTENDEE' iCalendar properties."** The parameter is read off a property
     * rather than off an attendee, because both carry it — and §3.2.2 will ask
     * the same question of the `ORGANIZER` an attendee's copy names.
     */
    public function testTheOrganizerCarriesItTheSameWay(): void
    {
        $object = $this->objectWith('ORGANIZER;SCHEDULE-AGENT=NONE:mailto:bernard@example.com');
        $organizer = $object->component('VEVENT')?->property('ORGANIZER');

        self::assertInstanceOf(Property::class, $organizer);
        self::assertSame(ScheduleAgent::None, ScheduleAgent::on($organizer));
    }

    /**
     * The parameter on the first `ATTENDEE` of an object built around one
     * line, read as the server reads it.
     */
    private function agentOn(string $line): ScheduleAgent
    {
        $attendee = $this->objectWith($line)->component('VEVENT')?->property('ATTENDEE');

        self::assertInstanceOf(Property::class, $attendee);

        return ScheduleAgent::on($attendee);
    }

    /**
     * One event carrying that line, read the way a `PUT` body is read.
     */
    private function objectWith(string $line): Component
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $line . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertInstanceOf(Component::class, $object);

        return $object;
    }
}
