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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\Acl\Principal;
use DavServices\Backend\ICalendarUserBackend;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the reply arriving at the organizer's own copy (RFC 6638
 * §4.2). R-CAL-32, P8-02h.
 *
 * This is the loop closed. P8-02g has an attendee's answer deliver a `REPLY`
 * to the organizer's Inbox; §4.2 is what the organizer's server then does with
 * it, and the whole of it happens inside the attendee's own `PUT`.
 *
 * > For a scheduling message reply sent by an "Attendee", the server first
 * > locates the corresponding scheduling object resource belonging to the
 * > "Organizer". If the corresponding scheduling object resource cannot be
 * > found, the server SHOULD ignore the scheduling message.
 * >
 * > The server MUST then update the "PARTSTAT" iCalendar property parameter
 * > value of each "ATTENDEE" iCalendar property in the scheduling object
 * > resource to match the changes indicated in the reply (taking into account
 * > the fact that an "Attendee" could have created a new overridden iCalendar
 * > component […]).
 * >
 * > The server MUST also update or add the "SCHEDULE-STATUS" property
 * > parameter on each matching "ATTENDEE" iCalendar property […].
 * >
 * > The scheduling message MUST only appear in the "Organizer's" scheduling
 * > Inbox collection once all automatic processing has been done.
 *
 * **What is in the reply and what it is applied to is {@see
 * \DavServices\CalDav\Schedule\Reply}'s** — this file is about the resource
 * being found, written and stored, and about the order the last sentence asks
 * for.
 *
 * ## Found by the `UID`, in any calendar of theirs
 *
 * The same search §4.1 makes for the other direction, and for the same reason:
 * RFC 5546 §2.1.5 makes the `UID` "the primary key for referencing a
 * particular iCalendar component", and RFC 4791 §4.1 makes it unique only "in
 * the scope of the calendar collection in which they are stored" — so the one
 * copy can be in any of them.
 *
 * ## And what "ignore" leaves standing
 *
 * §4.2's SHOULD is about the processing it then describes: there is no
 * resource to update, so nothing is updated. The notice stays in the Inbox all
 * the same, because that is what §4 puts one there for — "an indicator to the
 * client that a scheduling operation has taken place" — and a reply about an
 * event the organizer has deleted is something only they can make sense of
 * (RFC 5546 §3.2.3 leaves the uninvited attendee to them as well).
 */
#[CoversClass(Scheduling::class)]
final class SchedulingAnsweredTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    /** Cyrus's own copy, the one he answers with. */
    private const EVENT = 'calendars/cyrus/work/event.ics';

    /** Where Bernard keeps the event he organises. */
    private const THEIRS = 'calendars/bernard/work';

    private const ORGANISED = 'ORGANIZER:mailto:bernard@example.com';

    private const CYRUS = 'mailto:cyrus@example.com';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /** @var array<string, MemoryCollection> */
    private array $stores = [];

    /**
     * **The organizer's own copy records the answer**, which is §4.2's first
     * MUST, with the `2.0` its second asks for where the reply reports
     * nothing: "or to '2.0' if 'REQUEST-STATUS' is not present".
     *
     * Everything else of theirs is left as it was. §4.2 names two parameters
     * on one property, and the event is the organizer's.
     */
    public function testTheOrganizersOwnCopyRecordsTheAnswer(): void
    {
        $this->given($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS));

        self::assertSame(204, $this->answering('ACCEPTED'));
        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261101T100000Z\r\n"
            . "SEQUENCE:2\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->theStoredObject(self::THEIRS . '/event.ics'),
        );
    }

    /**
     * **And the reply is in their Inbox as well**, which §4 asks for in the
     * same breath as the processing: "In addition, the scheduling message is
     * stored in the scheduling Inbox collection as an indicator to the client
     * that a scheduling operation has taken place."
     */
    public function testTheReplyIsInTheirInboxAsWell(): void
    {
        $this->given($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS));
        $this->answering('ACCEPTED');

        self::assertStringContainsString(
            "METHOD:REPLY\r\n",
            $this->theOneResourceIn('calendars/bernard/inbox'),
        );
    }

    /**
     * **The answer is written on the copy wherever they keep it.** §4.2 has
     * the server locate "the corresponding scheduling object resource
     * belonging to the 'Organizer'" and says nothing about which collection it
     * is in — RFC 4791 §4.1 making a `UID` unique only "in the scope of the
     * calendar collection in which they are stored".
     *
     * @param string $where The calendar of Bernard's that holds his copy
     */
    #[DataProvider('theirCalendars')]
    public function testTheAnswerReachesTheirCopyInAnyOfTheirCalendars(string $where): void
    {
        $this->given($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS), $where);
        $this->answering('DECLINED');

        self::assertStringContainsString(
            'PARTSTAT=DECLINED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com',
            $this->theStoredObject($where . '/event.ics'),
        );
    }

    /**
     * Both of them, the order the home set names them in deciding nothing
     * here.
     *
     * @return iterable<string, array{string}>
     */
    public static function theirCalendars(): iterable
    {
        yield 'the first' => [self::THEIRS];
        yield 'the second' => ['calendars/bernard/private'];
    }

    /**
     * **An instance answered on its own reaches a component of its own**, and
     * §4.2's parenthesis is why there has to be one: "taking into account the
     * fact that an 'Attendee' could have created a new overridden iCalendar
     * component to indicate different participation status on one or more
     * instances of a recurring event".
     *
     * Cyrus declines one day of a series by leaving the instance out of his
     * own copy — §3.2.2.3's second sentence — and the organizer's copy, which
     * had nothing but an `RRULE`, comes to say which day he is not coming.
     */
    public function testAnInstanceAnsweredOnItsOwnReachesAComponentOfItsOwn(): void
    {
        $answered = 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS;

        $this->given($this->aSeries($answered), self::THEIRS, $this->aSeries($answered));

        self::assertSame(204, $this->put($this->aSeries($answered, 'EXDATE:20261102T100000Z')));
        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261101T100000Z\r\n"
            . "SEQUENCE:2\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261102T100000Z\r\n"
            . "SEQUENCE:2\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=DECLINED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->theStoredObject(self::THEIRS . '/event.ics'),
        );
    }

    /**
     * **An organizer with no copy of the event is left with the notice.**
     * §4.2: "If the corresponding scheduling object resource cannot be found,
     * the server SHOULD ignore the scheduling message." There is nothing to
     * update, and the delivery itself succeeded — §3.2.9's `1.2`, "The
     * scheduling message has been successfully delivered" — so the attendee's
     * own copy says the answer went.
     */
    public function testAnOrganizerWithNoCopyOfTheEventIsLeftWithTheNotice(): void
    {
        $this->given();

        self::assertSame(204, $this->answering('ACCEPTED'));
        self::assertStringContainsString("METHOD:REPLY\r\n", $this->theOneResourceIn('calendars/bernard/inbox'));
        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=1.2:mailto:bernard@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **A copy the answer reaches nothing on is stored exactly as it stood.**
     * Cyrus was taken off the event — §3.2.1.2's `<Removed>` column — and his
     * own copy is the one that has not caught up, so his answer names a
     * calendar user the organizer's copy no longer has a property for.
     *
     * The event here carries a line longer than RFC 5545 §3.1's "75 octets",
     * written out in one piece, because that is what makes the difference
     * visible: the serialiser folds it, so a resource written back at all
     * comes back changed whether anything was written on it or not.
     */
    public function testACopyTheAnswerReachesNothingOnIsStoredAsItStood(): void
    {
        $theirs = $this->anEvent(
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:eric@example.com',
            'SUMMARY:Lunch with Bernard, Eric and whoever else can make it on the day',
        );

        $this->given($theirs);

        self::assertSame(204, $this->answering('ACCEPTED'));
        self::assertSame($theirs, $this->theStoredObject(self::THEIRS . '/event.ics'));
    }

    /**
     * **And nothing reaches the Inbox where their copy cannot be read**,
     * which is the order §4.2's last sentence asks for: "The scheduling
     * message MUST only appear in the 'Organizer's' scheduling Inbox
     * collection once all automatic processing has been done."
     *
     * A notice without the processing behind it would tell the organizer's
     * client that something had happened to an event that still says nobody
     * has answered. §3.2.9's `5.1` is what the attendee is told instead: "the
     * server could not complete delivery of the message. This is likely a
     * temporary failure, and the originator can try to send the message again
     * at a later time."
     */
    public function testNothingReachesTheInboxWhereTheirCopyCannotBeRead(): void
    {
        $this->given($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS));

        $file = $this->theCollection(self::THEIRS)->child('event.ics');

        self::assertInstanceOf(MemoryFile::class, $file);

        $file->refuseReading();

        self::assertSame(204, $this->answering('ACCEPTED'));
        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=5.1:mailto:bernard@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * Cyrus answering the invitation he keeps at {@see self::EVENT}.
     */
    private function answering(string $answer): int
    {
        return $this->put($this->anEvent('ATTENDEE;PARTSTAT=' . $answer . ':' . self::CYRUS));
    }

    /**
     * A `PUT` of that body over Cyrus's own copy.
     */
    private function put(string $body): int
    {
        return ($this->server ?? self::fail('No server.'))
            ->handle(new Request('PUT', '/' . self::EVENT, body: new Body($body)))
            ->status();
    }

    /**
     * The event Bernard organises, carrying those properties.
     */
    private function anEvent(string ...$properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "SEQUENCE:2\r\n" . self::lines([self::ORGANISED, ...$properties])
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * The same as a daily series of three.
     */
    private function aSeries(string ...$properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "SEQUENCE:2\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . self::lines([self::ORGANISED, ...$properties])
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Those properties, each on a line of its own.
     *
     * @param array<array-key, string> $properties
     */
    private static function lines(array $properties): string
    {
        $written = '';

        foreach ($properties as $property) {
            $written .= $property . "\r\n";
        }

        return $written;
    }

    /**
     * The store behind the collection at that path.
     */
    private function theCollection(string $path): MemoryCollection
    {
        return $this->stores[$path] ?? self::fail('No such collection: ' . $path);
    }

    /**
     * What is stored at that path now.
     */
    private function theStoredObject(string $path): string
    {
        $node = ($this->tree ?? self::fail('No tree.'))->node($path);

        self::assertInstanceOf(IFile::class, $node);

        $content = $node->get();

        return is_string($content) ? $content : self::fail('The double keeps its content as a string.');
    }

    /**
     * The one resource in that collection.
     */
    private function theOneResourceIn(string $path): string
    {
        $names = $this->namesIn($path);

        self::assertCount(1, $names);

        return $this->theStoredObject(Path::join($path, $names[0] ?? self::fail('Nothing in ' . $path)));
    }

    /**
     * The names of what is in that collection.
     *
     * @return list<string>
     */
    private function namesIn(string $path): array
    {
        $collection = ($this->tree ?? self::fail('No tree.'))->node($path);

        self::assertInstanceOf(ICollection::class, $collection);

        $names = [];

        foreach ($collection->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }

    /**
     * Cyrus keeps the invitation he answers; Bernard organises from one of two
     * calendars and has the Inbox the reply goes to.
     *
     * @param string $theirs The organizer's own copy, or the empty string for
     *                       an organizer who has none — §4.2's SHOULD
     * @param string $where The calendar of theirs it is in
     * @param string $his What Cyrus keeps, or the empty string for the
     *                    invitation he has not answered yet
     */
    private function given(string $theirs = '', string $where = self::THEIRS, string $his = ''): void
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES);
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        $this->stores = [
            self::THEIRS => new MemoryCollection('work'),
            'calendars/bernard/private' => new MemoryCollection('private'),
            'calendars/bernard/inbox' => new MemoryCollection('inbox'),
            'calendars/cyrus/work' => new MemoryCollection('work'),
            'calendars/cyrus/inbox' => new MemoryCollection('inbox'),
        ];

        $this->stores['calendars/cyrus/work']->createFile(
            'event.ics',
            $his === '' ? $this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS) : $his,
        );

        if ($theirs !== '') {
            $this->theCollection($where)->createFile('event.ics', $theirs);
        }

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of($this->stores[self::THEIRS]))
                        ->add(Calendar::of($this->stores['calendars/bernard/private']))
                        ->add(Inbox::of($this->stores['calendars/bernard/inbox'])))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Calendar::of($this->stores['calendars/cyrus/work']))
                        ->add(Inbox::of($this->stores['calendars/cyrus/inbox']))))
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))),
        );

        $server = new Server($tree, $events, new Writer(['DAV:' => 'd', self::NS => 'C']));

        $server->onMethod('PUT', (new Put($server))(...));

        (new Scheduling($server, $users))->register();

        $this->tree = $tree;
        $this->server = $server;
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them.
     */
    private static function answer(PropertiesRequested $event, ICalendarUserBackend $users): void
    {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(self::HOME_SET, self::naming(self::HOME_SET, ['/calendars/' . $node->name() . '/']));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(self::ADDRESS_SET, self::naming(self::ADDRESS_SET, $users->addressesOf($node->name())));
            }

            return;
        }

        $segments = Path::segments($result->path());

        if (!$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, ['/principals/' . $segments[1] . '/']));
    }

    /**
     * One of the three properties, as a `DAV:href` list.
     *
     * @param list<string> $hrefs
     */
    private static function naming(string $property, array $hrefs): Element
    {
        $element = new Element($property);

        foreach ($hrefs as $href) {
            $child = new Element(self::HREF);

            $child->appendText($href);
            $element->append($child);
        }

        return $element;
    }
}
