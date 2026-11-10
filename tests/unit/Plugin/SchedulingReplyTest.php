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
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what an attendee's change sends (RFC 6638 §3.2.2.2 and
 * §3.2.2.3). R-CAL-32, P8-02g.
 *
 * ## The same `PUT`, read by the other section
 *
 * §3.2.3.1's table calls a write over a scheduling object resource a Modify
 * and leaves it there; which section describes the Modify is decided by whose
 * resource it is, which is §3.1's question. The owner being the `ORGANIZER`
 * makes it §3.2.1.2's and the owner being an `ATTENDEE` makes it §3.2.2.3's,
 * so this file is the other half of the same event's listener.
 *
 * ## What the attendee's side sends
 *
 * > If the "Attendee" changes one or more "PARTSTAT" iCalendar property values
 * > on any component, or adds an overridden component with a changed
 * > "PARTSTAT" property, then the server MUST deliver an iTIP "REPLY"
 * > scheduling message to the "Organizer" to indicate the new participation
 * > status of the "Attendee".
 *
 * One message and one recipient, which is what §3.1's second sentence made
 * room for: "the 'ORGANIZER' iCalendar property value […] MUST be the same in
 * every component".
 *
 * ## And what decides whether it sends anything at all
 *
 * The same table stands in §3.2.2.2, §3.2.2.3 and §3.2.2.4, over
 * `SCHEDULE-AGENT` on the `ORGANIZER` property: `SERVER` by default has the
 * server "attempt to process the update", and `CLIENT` and `NONE` each have it
 * do "no special processing of the resource". §7.1 adds the reading that makes
 * one property answer for the resource — "The parameter value MUST be the same
 * on every 'ORGANIZER' property in a scheduling object resource" — and that
 * anything unrecognised counts as `NONE`.
 *
 * ## What is not here
 *
 * **§4.2**, which is what the organizer's server does with the reply once it
 * arrives: "update the 'PARTSTAT' iCalendar property parameter value of each
 * 'ATTENDEE' iCalendar property in the scheduling object resource to match the
 * changes indicated in the reply". The message reaches the Inbox, and the
 * organizer's own event is left as it stands until that is done — the same
 * order P8-02c and P8-02d went in on the other side.
 *
 * **§3.2.2.4**, an attendee removing their copy, which hangs on §8.1's
 * `Schedule-Reply` request header.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingReplyTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    private const EVENT = 'calendars/cyrus/work/event.ics';

    private const ORGANISED = 'ORGANIZER:mailto:bernard@example.com';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /** @var array<string, MemoryCollection> */
    private array $stores = [];

    /**
     * **An answer changed is a `REPLY` in the organizer's Inbox**, which is
     * §3.2.2.3's first MUST and §4's own destination for a message: "the
     * scheduling message MUST only appear in the 'Organizer's' scheduling
     * Inbox collection once all automatic processing has been done".
     */
    public function testAnAnswerChangedReachesTheOrganizer(): void
    {
        $this->given($this->anEvent(self::ORGANISED, $this->answering('NEEDS-ACTION')));

        self::assertSame(204, $this->put($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED'))));

        $message = $this->theOneResourceIn('calendars/bernard/inbox');

        self::assertStringContainsString("METHOD:REPLY\r\n", $message);
        self::assertStringContainsString("ATTENDEE;CN=Cyrus;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n", $message);
    }

    /**
     * **And what came of the delivery is written on the `ORGANIZER`
     * property**, which is where §3.2.2.3 puts it: "'SCHEDULE-STATUS'
     * iCalendar property parameters are added or changed on 'ORGANIZER'
     * iCalendar properties in the scheduling object resource being modified as
     * described in Section 7.3, with the value set as described in Section
     * 3.2.9."
     *
     * `1.2` is that section's answer for a message delivered on this server —
     * "the scheduling message has been successfully delivered" — and §3.2.2.3
     * says what follows: "This will result in the updated calendar object
     * resource differing from the calendar data sent in the HTTP request."
     */
    public function testWhatCameOfTheDeliveryIsWrittenOnTheOrganizer(): void
    {
        $this->given($this->anEvent(self::ORGANISED, $this->answering('NEEDS-ACTION')));

        $this->put($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED')));

        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=1.2:mailto:bernard@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **Every `ORGANIZER` property is stamped and not just the first.**
     * §3.2.2.3 asks for the parameter "on 'ORGANIZER' iCalendar properties" in
     * the plural, and a recurring event with an overridden instance carries
     * one in each component — RFC 5546 §3.2.3's `ORGANIZER | 1` is per
     * component too.
     */
    public function testEveryOrganizerPropertyIsStamped(): void
    {
        $this->given($this->aSeriesAndAnInstance($this->answering('NEEDS-ACTION')));

        $this->put($this->aSeriesAndAnInstance($this->answering('ACCEPTED')));

        self::assertSame(
            2,
            substr_count($this->theStoredObject(self::EVENT), 'ORGANIZER;SCHEDULE-STATUS=1.2:'),
        );
    }

    /**
     * **A resource that stops being the owner's own event is compared against
     * nothing.** §3.2.3.1 calls this a Modify and RFC 5546 §3.2.2.4 calls it
     * "Changing the Organizer", which is a piece of work of its own; what has
     * to be decided here is which original §3.2.2.3's comparison reads.
     *
     * The answer is none. The comparison is between what this calendar user
     * said about an instance before and what they say now, and an answer on a
     * resource they organised themselves is not an answer to anybody's
     * invitation — §3.2.1 lets an organizer be an attendee of their own event.
     * So the copy they now have as an attendee is new, which is §3.2.2.2, and
     * what they say in it is theirs to say.
     *
     * The other reading is what makes this worth a case: an old answer of
     * their own, read as an answer they had already given, would keep the new
     * organizer from ever hearing it.
     */
    public function testAResourceThatStopsBeingTheOwnersOwnEventIsComparedAgainstNothing(): void
    {
        $this->given($this->anEvent(
            'ORGANIZER:mailto:cyrus@example.com',
            $this->answering('ACCEPTED'),
        ));

        self::assertSame(204, $this->put($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED'))));

        self::assertStringContainsString("METHOD:REPLY\r\n", $this->theOneResourceIn('calendars/bernard/inbox'));
    }

    /**
     * **An answer unchanged sends nothing and writes nothing.** A client that
     * reads the resource, sets an alarm of its own and writes it back has
     * changed no `PARTSTAT` — §3.2.2.1 allows that change expressly — and
     * §7.3's MUST NOT is the other half of it: "Servers MUST NOT set the
     * 'SCHEDULE-STATUS' property parameter on the 'ORGANIZER' property […] for
     * which it did not attempt to deliver a scheduling message."
     */
    public function testAnAnswerUnchangedSendsNothing(): void
    {
        $this->given($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED')));

        self::assertSame(204, $this->put($this->anEvent(
            self::ORGANISED,
            $this->answering('ACCEPTED'),
            'SUMMARY:Lunch',
        )));

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject(self::EVENT));
    }

    /**
     * **A copy created with an answer in it replies too**, which is §3.2.2.2's
     * case: a message that "can get delivered directly to the client (e.g.,
     * via email [RFC6047])" and that the attendee stores on the server.
     */
    public function testACopyCreatedWithAnAnswerReplies(): void
    {
        $this->given('');

        self::assertSame(201, $this->put(
            $this->anEvent(self::ORGANISED, $this->answering('DECLINED')),
            'calendars/cyrus/work/another.ics',
        ));

        self::assertStringContainsString(
            "ATTENDEE;CN=Cyrus;PARTSTAT=DECLINED:mailto:cyrus@example.com\r\n",
            $this->theOneResourceIn('calendars/bernard/inbox'),
        );
    }

    /**
     * **And one created without an answer replies nothing**, RFC 5545 §3.2.12
     * making the absent answer the default rather than a statement:
     * "NEEDS-ACTION […] is the default value". Which is what keeps §4 from
     * talking to itself — the copy §4.1 creates for an attendee carries
     * whatever the organizer sent, and a reply to that would answer the
     * invitation with its own words.
     */
    public function testACopyCreatedWithoutAnAnswerRepliesNothing(): void
    {
        $this->given('');

        self::assertSame(201, $this->put(
            $this->anEvent(self::ORGANISED, 'ATTENDEE:mailto:cyrus@example.com'),
            'calendars/cyrus/work/another.ics',
        ));

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
    }

    /**
     * **An instance excluded is a `REPLY` that declines it**, §3.2.2.3's
     * second MUST: "If the 'Attendee' adds an 'EXDATE' property value to
     * effectively remove a recurrence instance, the server MUST deliver an
     * iTIP 'REPLY' scheduling message to the 'Organizer' to indicate that the
     * 'Attendee' has declined the instance."
     */
    public function testAnInstanceExcludedIsDeclined(): void
    {
        $this->given($this->aSeries($this->answering('ACCEPTED')));

        self::assertSame(204, $this->put($this->aSeries($this->answering('ACCEPTED'), 'EXDATE:20261102T100000Z')));

        $message = $this->theOneResourceIn('calendars/bernard/inbox');

        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $message);
        self::assertStringContainsString("ATTENDEE;CN=Cyrus;PARTSTAT=DECLINED:mailto:cyrus@example.com\r\n", $message);
    }

    /**
     * **What §7.1 leaves to the client is left to the client.** The table
     * §3.2.2.3 shares with §3.2.2.2 and §3.2.2.4 has both of the other values
     * doing the same nothing: "The server does no special processing of the
     * resource."
     *
     * And an unrecognised value with them, §7.1 saying so outright: "Servers
     * and clients MUST treat x-name and iana-token values they do not
     * recognize the same way as they would the 'NONE' value."
     */
    #[DataProvider('theOrganizersThatAreNotTheServers')]
    public function testAnOrganizerTheServerIsNotActingForSendsNothing(string $organizer): void
    {
        $this->given($this->anEvent($organizer, $this->answering('NEEDS-ACTION')));

        self::assertSame(204, $this->put($this->anEvent($organizer, $this->answering('ACCEPTED'))));

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject(self::EVENT));
    }

    /**
     * The three §7.1 has the server keep out of.
     *
     * @return iterable<string, array{string}>
     */
    public static function theOrganizersThatAreNotTheServers(): iterable
    {
        yield 'CLIENT' => ['ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:bernard@example.com'];
        yield 'NONE' => ['ORGANIZER;SCHEDULE-AGENT=NONE:mailto:bernard@example.com'];
        yield 'something nobody knows' => ['ORGANIZER;SCHEDULE-AGENT=X-LATER:mailto:bernard@example.com'];
    }

    /**
     * **And the value that says the server does it, written out, still does
     * it.** §7.1 makes `SERVER` the default "in the absence of this
     * parameter", so a client that writes it out is asking for what it would
     * have got anyway.
     */
    public function testAnOrganizerSayingTheServerSendsItDoes(): void
    {
        $organizer = 'ORGANIZER;SCHEDULE-AGENT=SERVER:mailto:bernard@example.com';

        $this->given($this->anEvent($organizer, $this->answering('NEEDS-ACTION')));

        self::assertSame(204, $this->put($this->anEvent($organizer, $this->answering('ACCEPTED'))));

        self::assertStringContainsString("METHOD:REPLY\r\n", $this->theOneResourceIn('calendars/bernard/inbox'));
    }

    /**
     * **The reply goes to the organizer and to nobody else** — not to the
     * attendee who sent it, and not to the other attendees. §4.2 does ask for
     * the others to be told — "The server SHOULD send scheduling messages to
     * all the other 'Attendees' indicating the change in participation status"
     * — and puts it on the organizer's server, after it has processed the
     * reply.
     */
    public function testTheReplyGoesToTheOrganizerAlone(): void
    {
        $answering = 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com';

        $this->given($this->anEvent(self::ORGANISED, $this->answering('NEEDS-ACTION')));

        $this->put($this->anEvent(self::ORGANISED, $answering));

        self::assertCount(1, $this->namesIn('calendars/bernard/inbox'));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **A resource nothing was sent about is stored exactly as it arrived.**
     * §3.2.2.3 says what a delivery does to it — "This will result in the
     * updated calendar object resource differing from the calendar data sent
     * in the HTTP request" — and §7.3's MUST NOT is the other side of that
     * sentence: where nothing was delivered there is nothing to differ by.
     *
     * The body carries a line longer than RFC 5545 §3.1's "75 octets" wants,
     * written out in one piece, because that is what makes the difference
     * visible: the serialiser folds it, so a resource written back at all
     * comes back changed whether anything was stamped on it or not.
     *
     * @param string $organizer The `ORGANIZER` line, which decides whether the
     *                          server is acting at all
     * @param string $answer Cyrus's answer, unchanged from the original where
     *                       the case is about there being nothing to say
     */
    #[DataProvider('theWritesThatSendNothing')]
    public function testAResourceNothingWasSentAboutIsStoredAsItArrived(
        string $organizer,
        string $answer,
    ): void {
        $original = $this->anEvent($organizer, $this->answering('ACCEPTED'));
        $body = $this->anEvent(
            $organizer,
            $this->answering($answer),
            'SUMMARY:Lunch with Bernard, Cyrus, Eric and whoever else can make it on the day',
        );

        $this->given($original);

        self::assertSame(204, $this->put($body));
        self::assertSame($body, $this->theStoredObject(self::EVENT));
    }

    /**
     * The two reasons nothing goes: nothing to say, and nobody asking the
     * server to say it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function theWritesThatSendNothing(): iterable
    {
        yield 'the answer is the one that was there' => [self::ORGANISED, 'ACCEPTED'];
        yield 'the client is doing the scheduling' => [
            'ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:bernard@example.com',
            'DECLINED',
        ];
    }

    /**
     * **An organizer this server knows nothing about is reported rather than
     * refused.** §3.2.9's `3.7` is the code for it — "invalid calendar user" —
     * and §3.2.2.3 has the parameter say what came of the attempt, whatever
     * that was. An attendee's own answer is not lost because the organizer is
     * somewhere this server cannot reach.
     */
    public function testAnOrganizerTheServerCannotFindIsReported(): void
    {
        $elsewhere = 'ORGANIZER:mailto:nobody@example.net';

        $this->given($this->anEvent($elsewhere, $this->answering('NEEDS-ACTION')));

        self::assertSame(204, $this->put($this->anEvent($elsewhere, $this->answering('ACCEPTED'))));

        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=3.7:mailto:nobody@example.net',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **The organizer's own copy is left as it stands**, which is §4.2 and the
     * chunk after this one. The message is in the Inbox for the organizer to
     * find; the `PARTSTAT` on their event is not touched yet.
     */
    public function testTheOrganizersOwnCopyIsLeftAsItStands(): void
    {
        $theirs = $this->anEvent(self::ORGANISED, 'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com');

        $this->given($this->anEvent(self::ORGANISED, $this->answering('NEEDS-ACTION')));
        $this->inTheCalendar('calendars/bernard/work', 'theirs.ics', $theirs);

        $this->put($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED')));

        self::assertSame($theirs, $this->theStoredObject('calendars/bernard/work/theirs.ics'));
    }

    /**
     * **An attendee who takes the organizer off sends nothing yet.**
     * §3.2.3.1's table calls that "Scheduling object resource → Calendar
     * object resource: Remove", and §3.2.2.4 is the Remove it means — which
     * turns on §8.1's `Schedule-Reply` request header, and is a chunk of its
     * own. What must not happen is the organizer's Remove: §3.2.1.3's whole
     * event `CANCEL` is the organizer's to send, and an attendee cancelling an
     * event for everybody by editing their own copy would be the worst thing
     * in this file.
     */
    public function testAnAttendeeTakingTheOrganizerOffSendsNothing(): void
    {
        $this->given($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED')));

        self::assertSame(204, $this->put($this->anEvent('SUMMARY:Lunch')));

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
    }

    /**
     * **A resource that is neither side's scheduling object sends nothing.**
     * §3.1 asks for an `ORGANIZER` to be "present", and without one there is
     * nobody to reply to.
     */
    public function testAnEventWithoutAnOrganizerSendsNothing(): void
    {
        $this->given($this->anEvent('SUMMARY:Lunch'));

        self::assertSame(204, $this->put($this->anEvent('SUMMARY:Dinner')));

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
    }

    /**
     * **And a body that is no calendar object at all is nothing to do with
     * scheduling**, which is where this listener stops and
     * `CALDAV:valid-calendar-data` takes over.
     */
    public function testABodyThatIsNoCalendarObjectSendsNothing(): void
    {
        $this->given($this->anEvent(self::ORGANISED, $this->answering('ACCEPTED')));

        $this->put('not a calendar object');

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
    }

    /**
     * Cyrus's own `ATTENDEE` property, carrying that answer.
     */
    private function answering(string $answer): string
    {
        return 'ATTENDEE;CN=Cyrus;PARTSTAT=' . $answer . ':mailto:cyrus@example.com';
    }

    /**
     * Writes that body over the resource.
     */
    private function put(string $body, string $path = self::EVENT): int
    {
        return ($this->server ?? self::fail('No server.'))
            ->handle(new Request('PUT', '/' . $path, body: new Body($body)))
            ->status();
    }

    /**
     * One event carrying those properties.
     */
    private function anEvent(string ...$properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\nSEQUENCE:2\r\n"
            . self::lines($properties)
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * A recurring event Cyrus is invited to, carrying those properties.
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
     * A recurring event and one overridden instance, each organised by Bernard
     * and each carrying that property.
     */
    private function aSeriesAndAnInstance(string $attendee): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "SEQUENCE:2\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . self::lines([self::ORGANISED, $attendee])
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\nSEQUENCE:2\r\n"
            . self::lines([self::ORGANISED, $attendee])
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Those of these properties that are there, one to a line.
     *
     * @param array<array-key, string> $properties
     */
    private static function lines(array $properties): string
    {
        $written = '';

        foreach ($properties as $property) {
            if ($property !== '') {
                $written .= $property . "\r\n";
            }
        }

        return $written;
    }

    /**
     * Puts something into one of the collections.
     */
    private function inTheCalendar(string $path, string $name, string $content): void
    {
        $this->theCollection($path)->createFile($name, $content);
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
     * Cyrus keeps the copy an invitation left him; Bernard organises from a
     * calendar of his own and has the Inbox a reply goes to.
     *
     * @param string $original What is already at {@see self::EVENT}, or the
     *                         empty string for the cases that create one
     */
    private function given(string $original): void
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES);
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        $this->stores = [
            'calendars/bernard/work' => new MemoryCollection('work'),
            'calendars/bernard/inbox' => new MemoryCollection('inbox'),
            'calendars/cyrus/work' => new MemoryCollection('work'),
            'calendars/cyrus/inbox' => new MemoryCollection('inbox'),
        ];

        if ($original !== '') {
            $this->stores['calendars/cyrus/work']->createFile('event.ics', $original);
        }

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of($this->stores['calendars/bernard/work']))
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
