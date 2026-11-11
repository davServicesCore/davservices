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
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Request;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what an organizer's removal sends (RFC 6638 §3.2.1.3 and
 * §3.2.3.2). R-CAL-32, P8-02e.
 *
 * §3.2.1.3 is one table, and it is §3.2.1.1's with one word changed:
 *
 * > When an "Organizer" removes a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property to determine whether to send a scheduling
 * > message.
 * >
 * >                  +------------------+-------------+
 * >                  | SCHEDULE-AGENT   | iTIP METHOD |
 * >                  +------------------+-------------+
 * >                  | SERVER (default) | CANCEL      |
 * >                  | CLIENT           | --          |
 * >                  | NONE             | --          |
 * >                  +------------------+-------------+
 *
 * **And no `SCHEDULE-STATUS`**, which is the one way this operation differs
 * from the other two: §3.2.1.1 and §3.2.1.2 both have the server write what
 * came of the delivery onto the resource, and here there is no resource left
 * to write on. §3.2.1.3 asks for none.
 *
 * ## Which requests are a removal
 *
 * §3.2.3.2, both sentences:
 *
 * > When the server receives a DELETE method request targeted at a scheduling
 * > object resource, it MUST execute the Remove operation.
 * >
 * > When the server receives a DELETE method request targeted at a calendar
 * > collection, it MUST execute the Remove operation on all scheduling object
 * > resources contained in the calendar collection.
 *
 * The second comes out of the first here, because {@see Delete} takes a
 * collection apart member by member and each member's removal is its own.
 *
 * ## It is read before and sent after
 *
 * Nothing in §3.2.1.3 orders the two, and there is only one safe order: the
 * resource has to be read while it is still there, and the cancellation may
 * only go out once it really is gone. A store that refuses the deletion would
 * otherwise have left every attendee told that an event they can still see is
 * off.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingCancelTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /** @var array<string, MemoryCollection> */
    private array $stores = [];

    /**
     * **§3.2.1.3's table, first row: the attendee is sent a `CANCEL`.** RFC
     * 5546 §3.2.5 is what one looks like, and two of its rules are visible
     * here: `STATUS:CANCELLED` for the whole event, and a `SEQUENCE` one
     * further on than the resource carried (§2.1.4).
     */
    public function testTheAttendeeIsSentACancellation(): void
    {
        $this->given();
        $this->delete('calendars/bernard/work/event.ics');

        $message = $this->theOneResourceIn('calendars/cyrus/inbox');

        self::assertStringContainsString("METHOD:CANCEL\r\n", $message);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $message);
        self::assertStringContainsString("SEQUENCE:1\r\n", $message);
    }

    /**
     * **And §4 makes their own copy say it too.** The processing is the same
     * one an invitation goes through — "creating or updating the corresponding
     * scheduling object resources" — and what it stores is the message without
     * its `METHOD` (RFC 4791 §4.1). So the attendee is left with the event
     * they had, marked cancelled, which is what §4.1 means by "to reflect the
     * changes sent by the 'Organizer'".
     */
    public function testTheirOwnCopyIsMarkedCancelled(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com",
        ));
        $this->delete('calendars/bernard/work/event.ics');

        $kept = $this->theStoredObject('calendars/cyrus/work/theirs.ics');

        self::assertStringContainsString("STATUS:CANCELLED\r\n", $kept);
        self::assertStringNotContainsString('METHOD', $kept);
        self::assertSame(['theirs.ics'], $this->namesIn('calendars/cyrus/work'));
    }

    /**
     * **The second and third rows: nobody is told.** §7.1's `CLIENT` means
     * "the client is handling scheduling messages with the calendar user
     * itself", and `NONE` means nobody is being told at all — neither changes
     * because the event is going away.
     */
    public function testAnAttendeeThisServerDoesNotSendForIsNotTold(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'theirs.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com',
        ));
        $this->delete('calendars/bernard/work/theirs.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **§3.2.1's MUST NOT holds here as well**: "the server MUST NOT send a
     * scheduling message to the 'Attendee' that matches the 'Organizer'". An
     * organizer who deletes their own event does not need telling.
     */
    public function testTheOrganizerIsNotToldAboutTheirOwnEvent(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'mine.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:bernard@example.com",
        ));
        $this->delete('calendars/bernard/work/mine.ics');

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
    }

    /**
     * **An attendee behind one this server sends nothing for is still told.**
     * §7.1's `CLIENT` says nothing about anybody else on the list, and a
     * server that stopped at the first attendee it had nothing to do for would
     * leave the rest with an event that is off and no word about it.
     */
    public function testAnAttendeeBehindASkippedOneIsStillTold(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'both.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com
"
            . "ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:nobody@example.net
"
            . 'ATTENDEE:mailto:cyrus@example.com',
        ));
        $this->delete('calendars/bernard/work/both.ics');

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **And one behind the organizer themselves.** §3.2.1's MUST NOT is about
     * one `ATTENDEE` property, not about the rest of the list.
     */
    public function testAnAttendeeBehindTheOrganizerIsStillTold(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'both.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com
"
            . "ATTENDEE:mailto:bernard@example.com
"
            . 'ATTENDEE:mailto:cyrus@example.com',
        ));
        $this->delete('calendars/bernard/work/both.ics');

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **The first property naming a calendar user answers for all of them**,
     * which is §7.1's own sentence: "The parameter value MUST be the same on
     * each 'ATTENDEE' property whose values match in a scheduling object
     * resource." A resource that breaks it is read by the rule it broke, and
     * the overridden instance below says `NONE` where the master says nothing
     * at all.
     *
     * A server that let the last property answer instead would decide that one
     * instance of a series settles whether the whole event is cancelled.
     */
    public function testTheFirstPropertyNamingAnAttendeeAnswersForTheRest(): void
    {
        $this->given();
        $this->inTheCalendar(
            'calendars/bernard/work',
            'series.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;SCHEDULE-AGENT=NONE:mailto:cyrus@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );
        $this->delete('calendars/bernard/work/series.ics');

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **The same path twice is two removals.** A resource put back under a
     * name that has been used before is a new resource, and what was read of
     * the old one says nothing about it — so nothing of the first removal is
     * left lying about for the second to send.
     */
    public function testThePathTwiceIsTwoRemovals(): void
    {
        $this->given();
        $this->delete('calendars/bernard/work/event.ics');
        $this->inTheCalendar('calendars/bernard/work', 'event.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com
"
            . "ATTENDEE:mailto:cyrus@example.com
"
            . 'SUMMARY:The second one',
        ));
        $this->delete('calendars/bernard/work/event.ics');

        $names = $this->namesIn('calendars/cyrus/inbox');

        self::assertCount(2, $names);
        self::assertStringContainsString(
            'SUMMARY:The second one',
            implode('', array_map(
                fn (string $name): string => $this->theStoredObject(Path::join('calendars/cyrus/inbox', $name)),
                $names,
            )),
        );
    }

    /**
     * **And what was read of a removal is not left lying about.** A resource
     * put back under a name that has been used before and removed again sends
     * what **it** says, or nothing: the appointment here is nobody's
     * scheduling object resource (§3.1).
     *
     * The attendee acknowledges the first cancellation in between, which is
     * what §4 has clients do — "Scheduling messages are typically removed from
     * the scheduling Inbox collection by the client once the calendar user has
     * acknowledged the change" — and that is what makes a second sending of
     * the first message visible at all: it would arrive as a cancellation of
     * something nobody had scheduled.
     */
    public function testWhatWasReadOfARemovalIsNotLeftLyingAbout(): void
    {
        $this->given();
        $this->delete('calendars/bernard/work/event.ics');

        foreach ($this->namesIn('calendars/cyrus/inbox') as $acknowledged) {
            $this->theCollection('calendars/cyrus/inbox')->remove($acknowledged);
        }

        $this->inTheCalendar('calendars/bernard/work', 'event.ics', $this->anEvent('SUMMARY:Lunch'));
        $this->delete('calendars/bernard/work/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **An appointment somebody made for themselves sends nothing**, §3.1
     * asking for an `ORGANIZER` "present and set in all the calendar
     * components" before any of this applies.
     */
    public function testAnEventThatIsNobodysSchedulingObjectSendsNothing(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'lunch.ics', $this->anEvent('SUMMARY:Lunch'));
        $this->delete('calendars/bernard/work/lunch.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **And an attendee's own copy is not the organizer's to cancel.** §3.1's
     * second sentence makes this an attendee scheduling object resource, and
     * what an attendee's removal sends is §3.2.2.4's `REPLY` with
     * `PARTSTAT=DECLINED` — a different message, from a different section,
     * with §8.1's `Schedule-Reply` header deciding whether it goes at all.
     */
    public function testAnAttendeesOwnCopySendsNothingYet(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'theirs.ics', $this->anEvent(
            "ORGANIZER:mailto:cyrus@example.com\r\nATTENDEE:mailto:bernard@example.com",
        ));
        $this->delete('calendars/bernard/work/theirs.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **A resource outside a calendar collection is nothing to do with
     * scheduling**, whatever it holds (§3.1).
     */
    public function testAFileOutsideACalendarSendsNothing(): void
    {
        $this->given();
        $this->inTheCalendar('plain', 'event.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com",
        ));
        $this->delete('plain/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **And neither is a member this server cannot read as one calendar
     * object.** RFC 4791 §4.2 lets a calendar hold "collections that are not
     * calendar collections", and a deployment puts in it whatever it puts in
     * it.
     */
    public function testAMemberThatIsNoCalendarObjectSendsNothing(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'notes.txt', 'nothing to do with calendars');
        $this->theCollection('calendars/bernard/work')->createCollection('folder');
        $this->delete('calendars/bernard/work/notes.txt');
        $this->delete('calendars/bernard/work/folder');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertSame(['event.ics'], $this->namesIn('calendars/bernard/work'));
    }

    /**
     * **Nothing is sent where the removal itself did not happen.** The
     * cancellation goes out after the resource is gone, not before: a store
     * that refuses the deletion would otherwise have left every attendee told
     * that an event they can still see is off, and no second message would
     * ever put that right.
     */
    public function testNothingIsSentWhereTheRemovalFails(): void
    {
        $this->given();

        $node = ($this->tree ?? self::fail('No tree.'))->node('calendars/bernard/work/event.ics');

        self::assertInstanceOf(MemoryFile::class, $node);

        $node->refuseDeletion();

        self::assertSame(403, $this->delete('calendars/bernard/work/event.ics'));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertSame(['event.ics'], $this->namesIn('calendars/bernard/work'));
    }

    /**
     * **§3.2.3.2's second sentence: a calendar taken away cancels everything
     * in it.** "When the server receives a DELETE method request targeted at a
     * calendar collection, it MUST execute the Remove operation on all
     * scheduling object resources contained in the calendar collection."
     *
     * It comes out of the first sentence here, because {@see Delete} takes a
     * collection apart member by member — and the one member that is nobody's
     * scheduling object resource still sends nothing.
     */
    public function testACalendarTakenAwayCancelsEverythingInIt(): void
    {
        $this->given();
        $this->inTheCalendar('calendars/bernard/work', 'second.ics', str_replace(
            "UID:1\r\n",
            "UID:2\r\n",
            $this->anEvent("ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com"),
        ));
        $this->inTheCalendar('calendars/bernard/work', 'lunch.ics', $this->anEvent('SUMMARY:Lunch'));
        $this->delete('calendars/bernard/work');

        self::assertCount(2, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **A cancellation names the instances that attendee was on, and no
     * others.** §3.2.6's MUST holds for every message delivered to an
     * attendee — "when delivering scheduling messages for recurring calendar
     * components" — and RFC 5546 §3.2.5 has the form for it: "Individual
     * recurrence instances may be cancelled by specifying multiple 'VEVENT'
     * components each with a 'RECURRENCE-ID' property corresponding to one of
     * the instances to be cancelled."
     *
     * So an attendee invited to one instance of a series is told that one
     * instance is off, rather than being handed a series they were never on.
     * §4.4.3 of RFC 5546 is the example of exactly that message, down to the
     * `STATUS:CANCELLED` beside the `RECURRENCE-ID`.
     */
    public function testACancellationNamesTheInstancesThatAttendeeWasOn(): void
    {
        $this->given();
        $this->inTheCalendar(
            'calendars/bernard/work',
            'series.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:eric@example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );
        $this->delete('calendars/bernard/work/series.ics');

        $message = $this->theOneMessageIn('cyrus');

        self::assertSame(1, substr_count($message, 'BEGIN:VEVENT'));
        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $message);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $message);
        self::assertStringNotContainsString('RRULE', $message);
    }

    /**
     * The one message in that calendar user's Inbox.
     */
    private function theOneMessageIn(string $who): string
    {
        $path = 'calendars/' . $who . '/inbox';
        $names = $this->namesIn($path);

        self::assertCount(1, $names);

        return $this->theStoredObject($path . '/' . ($names[0] ?? self::fail('Nothing in ' . $path)));
    }

    /**
     * The `DELETE` of that path, and the status it was answered with.
     */
    private function delete(string $path): int
    {
        return ($this->server ?? self::fail('No server.'))
            ->handle(new Request('DELETE', '/' . $path))
            ->status();
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
        $name = $names[0] ?? self::fail(sprintf('Nothing in "%s".', $path));

        self::assertCount(1, $names);

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
     * Bernard organises and has an Inbox of his own; Cyrus attends, with an
     * Inbox and a calendar for his copy; and `plain` is no calendar at all.
     *
     * Bernard's calendar starts with one event he organises and Cyrus is
     * invited to, because every case here is about that event going away.
     */
    private function given(): void
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
            'plain' => new MemoryCollection('plain'),
        ];

        $this->stores['calendars/bernard/work']->createFile('event.ics', $this->anEvent(
            "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com",
        ));

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of($this->stores['calendars/bernard/work']))
                        ->add(Inbox::of($this->stores['calendars/bernard/inbox'])))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Calendar::of($this->stores['calendars/cyrus/work']))
                        ->add(Inbox::of($this->stores['calendars/cyrus/inbox']))))
                ->add($this->stores['plain'])
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))),
        );

        $server = new Server($tree, $events, new Writer(['DAV:' => 'd', self::NS => 'C']));

        $server->onMethod('DELETE', (new Delete($server))(...));

        (new Scheduling($server, $users))->register();

        $this->tree = $tree;
        $this->server = $server;
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them — the addresses out of the same table the directory answers
     * backwards from, which is what {@see ICalendarUserBackend} is for.
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
