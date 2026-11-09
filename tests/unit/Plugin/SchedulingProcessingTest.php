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
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what happens to a message once it arrives (RFC 6638 §4, §4.1
 * and §4.3). R-CAL-32, P8-02d.
 *
 * §4 opens with a MUST that P8-02c left standing:
 *
 * > Servers MUST automatically process incoming scheduling messages using the
 * > rules defined by [RFC5546], by creating or updating the corresponding
 * > scheduling object resources on calendars owned by the owner of the
 * > scheduling Inbox collection. In addition, the scheduling message is stored
 * > in the scheduling Inbox collection as an indicator to the client that a
 * > scheduling operation has taken place.
 *
 * So an invitation does two things: it becomes an event in the attendee's own
 * calendar, and it leaves a notice in their Inbox. §4.1 says which way round:
 *
 * > In each case, the scheduling message MUST only appear in the "Attendee's"
 * > scheduling Inbox collection once all automatic processing has been done.
 *
 * ## New or not is decided by the `UID`, and iTIP says so
 *
 * §4.1 has the server "first tr[y] to locate a corresponding scheduling object
 * resource belonging to the 'Attendee'", and RFC 5546 §3.2.2 — the rules §4
 * sends us to — says what corresponds:
 *
 * > If the "UID" property value in the "REQUEST" is not found on the
 * > recipient's calendar, then the "REQUEST" is for a new "VEVENT" calendar
 * > component. If the "UID" property value is found on the recipient's
 * > calendar, then the "REQUEST" is for a rescheduling, an update, or a
 * > reconfirmation of the "VEVENT" calendar component.
 *
 * **It keys on the `UID` alone**, which RFC 5545 §3.8.4.7 makes "the
 * persistent, globally unique identifier": two resources carrying one `UID`
 * are one event, and a client that reuses one has said so.
 *
 * ## Where a new one goes
 *
 * §4.3, and it is three sentences deep. "Servers SHOULD create new scheduling
 * object resources in the default calendar collection, if the
 * CALDAV:schedule-default-calendar-URL WebDAV property is set" — and §9.2 says
 * what the property holds, "zero or one DAV:href XML elements", with the
 * absence of either meaning there is none: "When there is no default calendar,
 * the server is free to choose the calendar in which a new scheduling object
 * resource is created."
 *
 * **And without any calendar there is nothing to choose**, which §4.3 makes a
 * MUST: "A calendar user that is an 'Attendee' in a scheduling operation MUST
 * have at least one valid calendar collection available. If there is no valid
 * calendar collection, then the server MUST reject the attempt to deliver the
 * scheduling message to the 'Attendee'." That rejection is §3.2.9's `5.3`, the
 * one code whose description says "was rejected".
 *
 * ## And the default calendar cannot be taken away
 *
 * §4.3: "Servers MUST reject any attempt to delete the default calendar
 * collection", with §4.3.1.1's `CALDAV:default-calendar-needed`.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingProcessingTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const DEFAULT_CALENDAR = '{' . self::NS . '}schedule-default-calendar-URL';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    private const EVENT = 'calendars/bernard/work/event.ics';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
        'eric' => ['mailto:eric@example.com'],
        'wilfredo' => ['mailto:wilfredo@example.com'],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /**
     * The stores behind the collections, by path, so that a case can put
     * something in a calendar before the message arrives.
     *
     * @var array<string, MemoryCollection>
     */
    private array $stores = [];

    /**
     * **§4: the invitation becomes an event in the attendee's own calendar.**
     * The whole resource, because what is kept is the message minus its
     * `METHOD` — RFC 4791 §4.1 forbids the property in a stored resource — and
     * nothing else about it is this server's to change.
     */
    public function testTheAttendeeGetsTheirOwnCopy(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261101T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->theOneResourceIn('calendars/cyrus/work'),
        );
    }

    /**
     * **And the message is in their Inbox as well**, which §4 asks for in the
     * same breath: "the scheduling message is stored in the scheduling Inbox
     * collection as an indicator to the client that a scheduling operation has
     * taken place". That copy keeps its `METHOD`, because it is the message.
     */
    public function testTheMessageIsInTheirInboxAsWell(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            "METHOD:REQUEST\r\n",
            $this->theOneResourceIn('calendars/cyrus/inbox'),
        );
    }

    /**
     * **§4.3's SHOULD: the default calendar is where a new one goes.**
     * "Servers SHOULD create new scheduling object resources in the default
     * calendar collection, if the CALDAV:schedule-default-calendar-URL WebDAV
     * property is set."
     */
    public function testTheCopyGoesToTheDefaultCalendarWhereThereIsOne(): void
    {
        $this->given(default: '/calendars/cyrus/private/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString('UID:1', $this->theOneResourceIn('calendars/cyrus/private'));
    }

    /**
     * **With no default, the server chooses.** §9.2: "In the absence of this
     * property, there is no default calendar. When there is no default
     * calendar, the server is free to choose the calendar in which a new
     * scheduling object resource is created." The first of their calendars is
     * the only choice this server can make without a rule the memo does not
     * state — the same reading it makes of §2.1.1's single `DAV:href`.
     */
    public function testWithNoDefaultTheServerChoosesTheFirstCalendar(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
        self::assertSame([], $this->namesIn('calendars/cyrus/private'));

        // And the first of the **first** home: RFC 4791 §6.2.1 allows more
        // than one, and the order the property names them in is the only order
        // there is.
        self::assertSame([], $this->namesIn('archive/cyrus/old'));
    }

    /**
     * **A property with no `DAV:href` is no default either**, which §9.2 says
     * outright: "When no DAV:href element is present, it indicates that there
     * is no default calendar."
     */
    public function testAPropertyWithNoHrefMeansNoDefault(): void
    {
        $this->given(default: '');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
    }

    /**
     * **A default that is not one of their calendars is passed over.** §4.3
     * ties the property to "a valid calendar collection belonging to the owner
     * of the scheduling Inbox collection", and what a store answers is read
     * rather than trusted: delivering into somebody else's calendar on the
     * strength of a property would be the worst way to honour a SHOULD.
     */
    public function testADefaultBelongingToSomebodyElseIsPassedOver(): void
    {
        $this->given(default: '/calendars/bernard/work/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
        self::assertCount(1, $this->namesIn('calendars/bernard/work'), 'only the organizer’s own resource');
    }

    /**
     * **And so is one that is no calendar collection at all**, which is the
     * other half of §4.3's "valid calendar collection": a message written into
     * an Inbox as though it were a calendar would be a second notice and no
     * event.
     */
    public function testADefaultThatIsNoCalendarIsPassedOver(): void
    {
        $this->given(default: '/calendars/cyrus/inbox/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'), 'the message, and nothing else');
    }

    /**
     * **And so is a default this server cannot address at all.** What a store
     * answers arrives here as text: an href that climbs above the root names
     * no calendar of theirs, and the free choice of §9.2 stands.
     */
    public function testADefaultThisServerCannotAddressIsPassedOver(): void
    {
        $this->given(default: '/../calendars/cyrus/private/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
    }

    /**
     * **A message about no event at all still becomes a resource.** RFC 5545
     * §3.6.1 makes `UID` REQUIRED in a `VEVENT`, so this is a client in the
     * wrong — but there is nothing to match it against either (RFC 5546 §3.2.2
     * matches by that very property), and a server that dropped it would have
     * delivered a notice to the Inbox for an event nobody can find.
     */
    public function testAMessageAboutNoEventIsStillKept(): void
    {
        $this->given();
        ($this->server ?? self::fail('No server.'))->handle(new Request(
            'PUT',
            '/' . self::EVENT,
            body: new Body(
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
                . "BEGIN:VEVENT\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
                . "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com\r\n"
                . "END:VEVENT\r\nEND:VCALENDAR\r\n",
            ),
        ));

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **§4.3's MUST: a calendar user with no calendar is rejected.** "A
     * calendar user that is an 'Attendee' in a scheduling operation MUST have
     * at least one valid calendar collection available. If there is no valid
     * calendar collection, then the server MUST reject the attempt to deliver
     * the scheduling message to the 'Attendee'."
     *
     * §3.2.9's `5.3` is the one code whose description says "was rejected", and
     * the Inbox stays empty: §4.1 lets the message appear there only "once all
     * automatic processing has been done", and here it never was.
     */
    public function testACalendarUserWithNoCalendarIsRejected(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:eric@example.com');

        self::assertSame([], $this->namesIn('calendars/eric/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.3:mailto:eric@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **A copy they already have is updated, not duplicated.** RFC 5546
     * §3.2.2: a `UID` found on the recipient's calendar makes the `REQUEST` "a
     * rescheduling, an update, or a reconfirmation", and §4.1 has the server
     * "updat[e] the matching scheduling object resource belonging to the
     * 'Attendee' to reflect the changes sent by the 'Organizer'".
     *
     * The resource keeps the name the attendee's client knows it by, which is
     * what makes it an update rather than a replacement: a client watching that
     * URL sees it change.
     */
    public function testACopyTheyAlreadyHaveIsUpdated(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', $this->anEvent('SUMMARY:As it was'));
        $this->put("ATTENDEE:mailto:cyrus@example.com\r\nSUMMARY:As the organizer has it");

        self::assertSame(['theirs.ics'], $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString(
            'SUMMARY:As the organizer has it',
            $this->theStoredObject('calendars/cyrus/work/theirs.ics'),
        );
    }

    /**
     * **And it is looked for in every calendar of theirs**, because §4.1 asks
     * about a resource "belonging to the 'Attendee'" rather than one in any
     * particular collection — and RFC 4791 §4.1 makes a `UID` unique only
     * "in the scope of the calendar collection in which they are stored", so
     * the one copy can be anywhere.
     */
    public function testTheirCopyIsFoundInAnyOfTheirCalendars(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/cyrus/private', 'theirs.ics', $this->anEvent('SUMMARY:As it was'));
        $this->put("ATTENDEE:mailto:cyrus@example.com\r\nSUMMARY:As the organizer has it");

        self::assertSame([], $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString(
            'SUMMARY:As the organizer has it',
            $this->theStoredObject('calendars/cyrus/private/theirs.ics'),
        );
    }

    /**
     * **Another event of theirs is another event.** The match is the `UID` and
     * nothing else, so a resource carrying a different one is left exactly
     * where it was — and the invitation arrives beside it.
     */
    public function testAnEventWithAnotherUidIsLeftAlone(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', str_replace(
            "UID:1\r\n",
            "UID:2\r\n",
            $this->anEvent('SUMMARY:Something else'),
        ));
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(2, $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString(
            'SUMMARY:Something else',
            $this->theStoredObject('calendars/cyrus/work/theirs.ics'),
        );
    }

    /**
     * **What is in a calendar besides calendar object resources is walked
     * past.** RFC 4791 §4.2 lets a calendar hold "collections that are not
     * calendar collections", and a member this server cannot read as one
     * calendar object carries no `UID` to match.
     */
    public function testWhatIsNoCalendarObjectIsWalkedPast(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/cyrus/work', 'notes.txt', 'nothing to do with calendars');
        $this->theCalendar('calendars/cyrus/work')->createCollection('folder');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertCount(3, $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString('UID:1', $this->theResourceIn('calendars/cyrus/work', 2));
    }

    /**
     * **§3.2.9's `5.1` for a store that will not take the copy** — "the server
     * could not complete delivery of the message. This is likely a temporary
     * failure" — **and the Inbox stays empty.** That is §4.1's ordering made
     * visible: "the scheduling message MUST only appear in the 'Attendee's'
     * scheduling Inbox collection once all automatic processing has been done".
     * A notice without the event behind it would tell a client to look for
     * something that is not there.
     */
    public function testACalendarThatWillNotTakeTheCopyIsSaidSo(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:wilfredo@example.com');

        self::assertSame([], $this->namesIn('calendars/wilfredo/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.1:mailto:wilfredo@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **The same message twice leaves one of each.** §4 makes the Inbox an
     * indicator, and the second delivery of one message has nothing to add —
     * least of all a copy that would overwrite an answer the calendar user has
     * given in the meantime.
     */
    public function testTheSameMessageTwiceLeavesOneOfEach(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/bernard/work/again.ics');

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));

        // And the second one is delivered, because it is: a server that wrote
        // the message into the Inbox again would have reported a failure about
        // a message the calendar user is looking at.
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject('calendars/bernard/work/again.ics'),
        );
    }

    /**
     * **A member that is no calendar object does not end the walk.** The one
     * copy can be behind anything a calendar holds — RFC 4791 §4.2 allows "
     * collections that are not calendar collections" in one — and a walk that
     * stopped at the first member it could not read would file a second copy
     * of an event the attendee already has.
     */
    public function testAMemberItCannotReadDoesNotHideTheirCopy(): void
    {
        $this->given();
        $this->theCalendar('calendars/cyrus/work')->createCollection('folder');
        $this->inTheCalendar('calendars/cyrus/work', 'notes.txt', 'nothing to do with calendars');
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', $this->anEvent('SUMMARY:As it was'));
        $this->put("ATTENDEE:mailto:cyrus@example.com\r\nSUMMARY:As the organizer has it");

        self::assertCount(3, $this->namesIn('calendars/cyrus/work'));
        self::assertStringContainsString(
            'SUMMARY:As the organizer has it',
            $this->theStoredObject('calendars/cyrus/work/theirs.ics'),
        );
    }

    /**
     * **§4.3's last MUST: the default calendar cannot be deleted.** "Servers
     * MUST reject any attempt to delete the default calendar collection", and
     * §4.3.1.1 is the code to say so with — `Apply to: DELETE`, `Use with: 403
     * Forbidden`.
     *
     * Without it a calendar user could take away the collection every
     * invitation to them is filed in, with one request and no way back.
     */
    public function testTheDefaultCalendarCannotBeDeleted(): void
    {
        $this->given(default: '/calendars/cyrus/private/');
        $answer = $this->delete('calendars/cyrus/private');

        self::assertSame(403, $answer->status());
        self::assertStringContainsString('default-calendar-needed', (string) $answer->body());
    }

    /**
     * **And the refusal says what is wrong before it says which precondition
     * that broke.** Asking for the precondition alone would let half a message
     * pass for a whole one, and the half a person reads is the sentence.
     */
    public function testTheRefusalSaysWhatIsWrongAndThenWhichPrecondition(): void
    {
        $this->given(default: '/calendars/cyrus/private/');

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessageMatches('/not to be deleted.*default-calendar-needed precondition/s');

        ($this->server ?? self::fail('No server.'))->events()->emit(new BeforeUnbind('calendars/cyrus/private'));
    }

    /**
     * **A default that is no calendar collection is not protected either.**
     * §4.3 ties the property to "a valid calendar collection", and a server
     * that refused the deletion of something else because a property happened
     * to name it would make a folder undeletable by guesswork — the same
     * reading that keeps such a default from being delivered into.
     */
    public function testADefaultThatIsNoCalendarIsNotProtected(): void
    {
        $this->given(default: '/calendars/cyrus/notes/');

        self::assertSame(204, $this->delete('calendars/cyrus/notes')->status());
    }

    /**
     * **And an ordinary folder in their home goes without a word from this
     * plugin.** RFC 4791 §4.2 allows one, and nothing in RFC 6638 protects it:
     * a guard that asked a collection what kind of resource it is has to cope
     * with one that says nothing at all.
     */
    public function testAnOrdinaryFolderInTheirHomeGoes(): void
    {
        $this->given();

        self::assertSame(204, $this->delete('calendars/cyrus/notes')->status());
    }

    /**
     * **And another calendar of theirs can be.** The guard is for the one
     * collection §4.3 names and not for calendars in general: a user who may
     * tidy up their own calendars keeps that.
     */
    public function testAnotherCalendarOfTheirsCanBeDeleted(): void
    {
        $this->given(default: '/calendars/cyrus/private/');

        self::assertSame(204, $this->delete('calendars/cyrus/work')->status());
    }

    /**
     * **A calendar this server names no owner for is nobody's default.**
     * Whose a calendar is comes from `DAV:owner`, and without one there is no
     * Inbox to ask — so there is nothing to refuse, and a server that refused
     * anyway would have made a calendar undeletable by guesswork.
     */
    public function testACalendarWithNoOwnerIsNobodysDefault(): void
    {
        $this->given(default: '/calendars/orphan/');

        self::assertSame(204, $this->delete('calendars/orphan')->status());
    }

    /**
     * **And neither is one whose owner has no Inbox.** §2.2.1 reads that as a
     * calendar user "not enabled for reception of scheduling messages on the
     * server", and such a user has no property naming a default calendar
     * either. Bernard organises and receives nothing.
     */
    public function testACalendarWhoseOwnerHasNoInboxIsNotProtected(): void
    {
        $this->given(default: '/calendars/bernard/work/');

        self::assertSame(204, $this->delete('calendars/bernard/work')->status());
    }

    /**
     * **Where no default is named, nothing is protected by this guard.** §9.2:
     * "In the absence of this property, there is no default calendar" — and a
     * server that guessed one would refuse a deletion it has no reason to
     * refuse.
     */
    public function testWithNoDefaultNoCalendarIsProtected(): void
    {
        $this->given();

        self::assertSame(204, $this->delete('calendars/cyrus/private')->status());
    }

    /**
     * The `PUT` of an event organised by Bernard carrying those properties.
     */
    private function put(string $properties, string $path = self::EVENT): void
    {
        ($this->server ?? self::fail('No server.'))->handle(new Request(
            'PUT',
            '/' . $path,
            body: new Body($this->anEvent("ORGANIZER:mailto:bernard@example.com\r\n" . $properties)),
        ));
    }

    /**
     * A `DELETE` of that path, and the answer it was given.
     */
    private function delete(string $path): Response
    {
        return ($this->server ?? self::fail('No server.'))->handle(new Request('DELETE', '/' . $path));
    }

    /**
     * One event carrying those properties.
     */
    private function anEvent(string $properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $properties . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Puts something into one of the collections before the message arrives.
     */
    private function inTheCalendar(string $path, string $name, string $content): void
    {
        $this->theCalendar($path)->createFile($name, $content);
    }

    /**
     * The store behind the collection at that path.
     */
    private function theCalendar(string $path): MemoryCollection
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
        self::assertCount(1, $this->namesIn($path));

        return $this->theResourceIn($path, 0);
    }

    /**
     * The content of the member at that position.
     */
    private function theResourceIn(string $path, int $at): string
    {
        $names = $this->namesIn($path);
        $name = $names[$at] ?? self::fail(sprintf('Nothing at %d in "%s".', $at, $path));

        return $this->theStoredObject(Path::join($path, $name));
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
     * Bernard organises and receives nothing; Cyrus has an Inbox and two
     * calendars; Eric has an Inbox and no calendar at all (§4.3's rejection);
     * Wilfredo's calendar will not take what it is handed; and
     * `calendars/orphan` is a calendar this server names no owner for, its
     * path being two segments deep where the rule below wants three.
     *
     * @param string|null $default What the Inboxes answer for
     *                             `CALDAV:schedule-default-calendar-URL`: null
     *                             for not answering it at all (§9.2's "In the
     *                             absence of this property"), the empty string
     *                             for the property with no `DAV:href` in it,
     *                             and a path for one naming that
     */
    private function given(?string $default = null): void
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES);
        $events = new EventEmitter();

        $events->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event) use ($users, $default): void {
                self::answer($event, $users, $default);
            },
        );

        $this->stores = [
            'calendars/bernard/work' => new MemoryCollection('work'),
            'calendars/cyrus/inbox' => new MemoryCollection('inbox'),
            'calendars/cyrus/work' => new MemoryCollection('work'),
            'calendars/cyrus/private' => new MemoryCollection('private'),
            'calendars/cyrus/notes' => new MemoryCollection('notes'),
            'archive/cyrus/old' => new MemoryCollection('old'),
            'calendars/eric/inbox' => new MemoryCollection('inbox'),
            'calendars/wilfredo/inbox' => new MemoryCollection('inbox'),
            'calendars/wilfredo/work' => (new MemoryCollection('work'))->refuseCreation(),
            'calendars/orphan' => new MemoryCollection('orphan'),
        ];

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of($this->stores['calendars/bernard/work'])))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Inbox::of($this->stores['calendars/cyrus/inbox']))
                        ->add(Calendar::of($this->stores['calendars/cyrus/work']))
                        ->add(Calendar::of($this->stores['calendars/cyrus/private']))
                        ->add($this->stores['calendars/cyrus/notes']))
                    ->add((new MemoryCollection('eric'))
                        ->add(Inbox::of($this->stores['calendars/eric/inbox'])))
                    ->add((new MemoryCollection('wilfredo'))
                        ->add(Inbox::of($this->stores['calendars/wilfredo/inbox']))
                        ->add(Calendar::of($this->stores['calendars/wilfredo/work'])))
                    ->add(Calendar::of($this->stores['calendars/orphan'])))
                ->add((new MemoryCollection('archive'))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Calendar::of($this->stores['archive/cyrus/old']))))
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))
                    ->add(new Principal(new PrincipalInfo('eric', 'Eric')))
                    ->add(new Principal(new PrincipalInfo('wilfredo', 'Wilfredo')))),
        );

        $server = new Server($tree, $events, new Writer(['DAV:' => 'd', self::NS => 'C']));

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('DELETE', (new Delete($server))(...));

        (new Scheduling($server, $users))->register();

        $this->tree = $tree;
        $this->server = $server;
    }

    /**
     * The four properties this plugin reads, answered the way a deployment
     * answers them — the addresses out of the same table the directory answers
     * backwards from, which is what {@see ICalendarUserBackend} is for.
     *
     * **`DAV:owner` is answered for everything three segments deep**, and not
     * only for calendars: a store knows whose every resource of a home is, and
     * a fixture that answered it only where this plugin happens to ask would
     * be answering the plugin rather than the property.
     */
    private static function answer(
        PropertiesRequested $event,
        ICalendarUserBackend $users,
        ?string $default,
    ): void {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $homes = ['/calendars/' . $node->name() . '/'];

                // RFC 4791 §6.2.1: "There can be multiple calendar collection
                // home sets per user." Cyrus has two, so that the order the
                // property names them in decides something.
                if ($node->name() === 'cyrus') {
                    $homes[] = '/archive/cyrus/';
                }

                $result->set(self::HOME_SET, self::naming(self::HOME_SET, $homes));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(self::ADDRESS_SET, self::naming(self::ADDRESS_SET, $users->addressesOf($node->name())));
            }

            return;
        }

        if ($node instanceof Inbox && $default !== null && $result->wants(self::DEFAULT_CALENDAR)) {
            $result->set(
                self::DEFAULT_CALENDAR,
                self::naming(self::DEFAULT_CALENDAR, $default === '' ? [] : [$default]),
            );
        }

        $segments = Path::segments($result->path());

        if (!$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, ['/principals/' . $segments[1] . '/']));
    }

    /**
     * A property holding a `DAV:href` for each of those.
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
