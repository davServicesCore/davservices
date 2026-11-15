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
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\CalDav\Schedule\ScheduleResponse;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Post;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §5, a busy time request on an Outbox. R-CAL-34,
 * P8-05.
 *
 * > Busy time information of one or more calendar users can be determined by
 * > submitting a POST request targeted at the scheduling Outbox collection of
 * > the calendar user requesting the information (the "Organizer").
 *
 * Three sentences of §5 are three guards, and each is asked for here on its
 * own:
 *
 * - the body "MUST contain a 'VFREEBUSY' calendar component with the 'METHOD'
 *   iCalendar property set to the value 'REQUEST'" — §5.2.1's precondition,
 *   `400`;
 * - "The resource identified by the Request-URI MUST be a resource collection
 *   of type CALDAV:schedule-outbox" — §5.1's `403`, "The client cannot submit
 *   a scheduling message to the specified Request-URI";
 * - "The 'ORGANIZER' property value in the 'VFREEBUSY' component MUST match
 *   one of the calendar user addresses of the owner of the Outbox collection"
 *   — §5.2.2's precondition, `403`.
 *
 * ## A recipient is answered, not refused
 *
 * §5 has the answer carry one response per calendar user "containing elements
 * that indicate ... the scheduling status for that calendar user", and
 * Appendix B.5 shows what that is for: Mike, whom the server knows nothing
 * about, gets `3.7;Invalid calendar user` beside the two who answered. So an
 * address nobody is reachable at is part of the answer rather than a reason
 * to refuse the question.
 *
 * ## One MUST NOT comes from somewhere else entirely
 *
 * §9.1 is about a property, and it binds this computation:
 *
 * > When the CALDAV:transparent XML element is used, the calendar object
 * > resources in the corresponding calendar collection MUST NOT contribute to
 * > busy time.
 *
 * And the default is not silence: "If this property is not present on a
 * calendar collection, then the default value CALDAV:opaque MUST be assumed."
 * Both halves are asked for here, because a server that read the property and
 * got the default wrong would hide every calendar that never set it.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingFreeBusyTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const CYRUS = 'mailto:cyrus@example.com';

    private const WILFREDO = 'mailto:wilfredo@example.com';

    private const BERNARD = 'mailto:bernard@example.net';

    private const MIKE = 'mailto:mike@example.org';

    private const ADDRESSES = [
        'cyrus' => [self::CYRUS],
        'wilfredo' => [self::WILFREDO],
        'bernard' => [self::BERNARD],
    ];

    private const TRANSP = '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp';

    private const OWNER = '{DAV:}owner';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    private const OUTBOX = '/calendars/cyrus/outbox';

    /**
     * **Appendix B.5's own request.** `200`, an XML document, and
     * §10.1's root element — the shape a client parses before it reads
     * anything.
     */
    public function testItAnswersABusyTimeRequestWithAScheduleResponse(): void
    {
        $response = $this->post($this->aRequest([self::WILFREDO]));

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/xml', $response->headers()->first('Content-Type') ?? '');
        self::assertStringContainsString('<C:schedule-response', (string) $response->body());
    }

    /**
     * **The busy time of whoever was asked about.** Wilfredo's calendar holds
     * one hour of the range, and that hour comes back as iTIP's own
     * `FREEBUSY` property inside a `METHOD:REPLY` object, which is what
     * Appendix B.5 writes.
     */
    public function testItReportsTheBusyTimeFoundInTheRecipientsCalendars(): void
    {
        $body = (string) $this->post($this->aRequest([self::WILFREDO]))->body();

        self::assertStringContainsString(ScheduleResponse::SUCCESS, $body);
        self::assertStringContainsString('METHOD:REPLY', $body);
        self::assertStringContainsString('FREEBUSY', $body);
        self::assertStringContainsString('20090602T110000Z/20090602T120000Z', $body);
    }

    /**
     * **The reply is an iTIP reply, and RFC 5546 §3.3.3 says what one
     * carries.** `METHOD` MUST be REPLY; `ORGANIZER | 1` "MUST be the request
     * originator's address"; `ATTENDEE | 1` "MUST be the address of the
     * Attendee replying"; and `UID | 1` "MUST be the UID of the original
     * REQUEST", which is how a client pairs answer with question. RFC 5545
     * §3.6 adds `VERSION` and `PRODID` to the object around it.
     *
     * @param string $written A line Appendix B.5's reply carries
     */
    #[DataProvider('whatAnITipReplyCarries')]
    public function testTheReplyCarriesWhatAnITipReplyMustCarry(string $written): void
    {
        self::assertStringContainsString(
            $written,
            self::unfolded((string) $this->post($this->aRequest([self::WILFREDO]))->body()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function whatAnITipReplyCarries(): array
    {
        return [
            'METHOD | 1, REPLY' => ['METHOD:REPLY'],
            'ORGANIZER | 1, the originator' => ['ORGANIZER:' . self::CYRUS],
            'ATTENDEE | 1, the one replying' => ['ATTENDEE:' . self::WILFREDO],
            'UID | 1, the one of the REQUEST' => ['UID:4FD3AD926350'],
            'VERSION, which RFC 5545 §3.6 requires' => ['VERSION:2.0'],
            'PRODID, which §3.7.3 requires' => ['PRODID:'],
        ];
    }

    /**
     * **A request that reaches more objects than the server will is refused
     * even inside the dates.** §11.1 is about resources, and a calendar of
     * two objects with a limit of one is the smallest case that counts past
     * the first — which a server counting the wrong way would never reach.
     */
    public function testItStopsCountingAtTheLimitRatherThanAwayFromIt(): void
    {
        $response = $this->post($this->aRequest([self::WILFREDO]), atMost: 1);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('number-of-matches-within-limits', (string) $response->body());
    }

    /**
     * **And a limit the request stays under is answered.**
     */
    public function testItAnswersARequestThatStaysUnderTheLimit(): void
    {
        self::assertSame(200, $this->post($this->aRequest([self::WILFREDO]), atMost: 2)->status());
    }

    /**
     * **And one response for each, in the order asked.** §5: "This element
     * MUST contain one CALDAV:response element for each calendar user."
     */
    public function testItAnswersEachCalendarUserInTheOrderTheyWereAsked(): void
    {
        $document = $this->documentOf($this->post($this->aRequest([self::WILFREDO, self::BERNARD])));

        self::assertSame([self::WILFREDO, self::BERNARD], self::recipientsIn($document));
    }

    /**
     * **Nobody at that address is an answer, not a refusal** — Appendix B.5's
     * `3.7;Invalid calendar user`, and no `calendar-data` beside it, because
     * §5 has that element "For a successful busy time request".
     */
    public function testItReportsAnAddressNobodyIsReachableAtRatherThanRefusingTheRequest(): void
    {
        $document = $this->documentOf($this->post($this->aRequest([self::MIKE])));
        $response = $document->child('{' . self::NS . '}response');

        self::assertSame([self::MIKE], self::recipientsIn($document));
        self::assertSame(
            ScheduleResponse::NO_SUCH_USER,
            $response?->child('{' . self::NS . '}request-status')?->text(),
        );
        self::assertNull($response->child('{' . self::NS . '}calendar-data'));
    }

    /**
     * **And the one who could be found is still answered.** A request naming
     * both must not lose the good half to the bad one — §5 wants one response
     * for each.
     */
    public function testItAnswersTheOnesItFoundBesideTheOneItDidNot(): void
    {
        $document = $this->documentOf($this->post($this->aRequest([self::MIKE, self::WILFREDO])));

        self::assertSame([self::MIKE, self::WILFREDO], self::recipientsIn($document));
        self::assertSame(
            [ScheduleResponse::NO_SUCH_USER, ScheduleResponse::SUCCESS],
            self::statusesIn($document),
        );
    }

    /**
     * **§9.1's MUST NOT: a transparent calendar contributes nothing.**
     * Bernard's second calendar holds an hour of the range and says
     * `CALDAV:transparent`, so that hour is not reported — while the hour in
     * his opaque one is.
     */
    public function testACalendarThatSaysItIsTransparentContributesNoBusyTime(): void
    {
        $body = (string) $this->post($this->aRequest([self::BERNARD]))->body();

        self::assertStringContainsString('20090602T150000Z/20090602T160000Z', $body);
        self::assertStringNotContainsString('20090603T090000Z/20090603T100000Z', $body);
    }

    /**
     * **And a calendar that says nothing is opaque.** §9.1: "If this property
     * is not present on a calendar collection, then the default value
     * CALDAV:opaque MUST be assumed." Wilfredo's calendar never set it, and
     * his hour is reported.
     */
    public function testACalendarThatSaysNothingAboutTransparencyIsOpaque(): void
    {
        self::assertStringContainsString(
            '20090602T110000Z/20090602T120000Z',
            (string) $this->post($this->aRequest([self::WILFREDO]))->body(),
        );
    }

    /**
     * **§5: the target must be an Outbox.** §5.1 gives the status in its own
     * words — `403`, "The client cannot submit a scheduling message to the
     * specified Request-URI".
     *
     * @param string $target Somewhere that is not a scheduling Outbox
     */
    #[DataProvider('placesThatAreNoOutbox')]
    public function testItRefusesABusyTimeRequestAnywhereButOnAnOutbox(string $target): void
    {
        self::assertSame(403, $this->post($this->aRequest([self::WILFREDO]), $target)->status());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function placesThatAreNoOutbox(): array
    {
        return [
            'a calendar' => ['/calendars/cyrus/work'],
            'an Inbox' => ['/calendars/cyrus/inbox'],
            'a plain collection' => ['/calendars'],
        ];
    }

    /**
     * **§5.2.2: the organizer is the owner of the Outbox.** The precondition
     * has a name and a status of its own — `CALDAV:valid-organizer` with
     * `403` — because the body is well formed and the person asking is simply
     * not whose Outbox this is.
     */
    public function testItRefusesAnOrganizerWhoDoesNotOwnTheOutbox(): void
    {
        $response = $this->post($this->aRequest([self::WILFREDO], organizer: self::BERNARD));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-organizer', (string) $response->body());
    }

    /**
     * **§5.2.1: a body that breaks RFC 5546 §3.3.2 is refused with `400`**
     * and the precondition that section names.
     */
    public function testItRefusesABodyThatDoesNotObeyITip(): void
    {
        $response = $this->post("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");

        self::assertSame(400, $response->status());
        self::assertStringContainsString('valid-scheduling-message', (string) $response->body());
    }

    /**
     * **Without a directory, nobody is reachable — but the organizer is still
     * who they say.** The two directions of §2.4.1 are not one question:
     * §5.2.2 compares the `ORGANIZER` against the Outbox owner's
     * `CALDAV:calendar-user-address-set`, which is a property and is read as
     * one, while finding *whose* Inbox an address belongs to is the reverse
     * map and needs the directory.
     *
     * So a server with no directory answers the request and reports `3.7` for
     * every recipient — §2.4.1's "If not present, then the associated
     * calendar user is not enabled for scheduling on the server", said once
     * per name asked about. Refusing the whole request instead would tell the
     * organizer their own Outbox is not theirs.
     */
    public function testWithoutADirectoryEveryRecipientIsReportedAsUnknown(): void
    {
        $response = $this->post($this->aRequest([self::WILFREDO, self::BERNARD]), directory: false);
        $document = $this->documentOf($response);

        self::assertSame(200, $response->status());
        self::assertSame([self::WILFREDO, self::BERNARD], self::recipientsIn($document));
        self::assertSame(
            [ScheduleResponse::NO_SUCH_USER, ScheduleResponse::NO_SUCH_USER],
            self::statusesIn($document),
        );
    }

    /**
     * **The organizer asking about themselves is answered like anybody
     * else.** §5 names no exception, and §3.2.1's "MUST NOT send a scheduling
     * message to the 'Attendee' that matches the 'Organizer'" is about
     * sending a message rather than about reporting busy time — a person
     * asking when they are busy is the ordinary case of a client drawing a
     * calendar.
     */
    public function testTheOrganizerAskingAboutThemselvesIsAnsweredLikeAnybodyElse(): void
    {
        $document = $this->documentOf($this->post($this->aRequest([self::CYRUS])));

        self::assertSame([self::CYRUS], self::recipientsIn($document));
        self::assertSame([ScheduleResponse::SUCCESS], self::statusesIn($document));
    }

    /**
     * **§11.1's MUST, by the means RFC 4791 already gives.** "Servers MUST
     * ensure that clients cannot consume excessive server resources by
     * carrying out 'large' scheduling operations" — and a request for busy
     * time from the year 1200 is such an operation. §5.2.6's
     * `CALDAV:min-date-time` is the sentence that bounds it, which is why the
     * refusal names it.
     */
    public function testItRefusesARangeOutsideTheDatesThisServerKeeps(): void
    {
        $response = $this->post($this->aRequest(
            [self::WILFREDO],
            start: 'DTSTART:12000602T000000Z',
            end: 'DTEND:12000604T000000Z',
        ));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('min-date-time', (string) $response->body());
    }

    /**
     * **And a range inside them is answered**, so that the guard above cannot
     * be one that refuses every request.
     */
    public function testItAnswersARangeInsideThoseDates(): void
    {
        self::assertSame(200, $this->post($this->aRequest([self::WILFREDO]))->status());
    }

    /**
     * **A range inside the dates can still reach everything.** §11.1 is about
     * resources rather than about dates, so the number of calendar object
     * resources one request reaches is bounded too — the same guard
     * `FreeBusyQuery` puts on the same computation, answered with the same
     * precondition.
     */
    public function testItRefusesARequestThatWouldReachMoreObjectsThanItWill(): void
    {
        $response = $this->post($this->aRequest([self::WILFREDO]), atMost: 0);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('number-of-matches-within-limits', (string) $response->body());
    }

    /**
     * Appendix B.5's request, with the parts a case wants changed.
     *
     * @param list<string> $recipients
     */
    private function aRequest(
        array $recipients,
        string $organizer = self::CYRUS,
        string $start = 'DTSTART:20090602T000000Z',
        string $end = 'DTEND:20090604T000000Z',
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Example Corp.//CalDAV Client//EN',
            'METHOD:REQUEST',
            'BEGIN:VFREEBUSY',
            'UID:4FD3AD926350',
            'DTSTAMP:20090602T190420Z',
            $start,
            $end,
            'ORGANIZER:' . $organizer,
        ];

        foreach ($recipients as $recipient) {
            $lines[] = 'ATTENDEE:' . $recipient;
        }

        return implode("\r\n", [...$lines, 'END:VFREEBUSY', 'END:VCALENDAR']) . "\r\n";
    }

    private function post(
        string $body,
        string $target = self::OUTBOX,
        bool $directory = true,
        int $atMost = 5_000,
    ): Response {
        return $this->server($directory, $atMost)->handle(new Request(
            'POST',
            $target,
            headers: new Headers(['Content-Type' => 'text/calendar; charset="utf-8"']),
            body: new Body($body),
        ));
    }

    /**
     * The document that came back, parsed.
     */
    private function documentOf(Response $response): Element
    {
        return $this->server()->reader()->parse((string) $response->body());
    }

    /**
     * The addresses the responses are for, in order.
     *
     * @return list<string>
     */
    private static function recipientsIn(Element $document): array
    {
        $found = [];

        foreach ($document->children() as $response) {
            $found[] = $response->child('{' . self::NS . '}recipient')?->child('{DAV:}href')?->text() ?? '';
        }

        return $found;
    }

    /**
     * And the status of each, in the same order.
     *
     * @return list<string>
     */
    private static function statusesIn(Element $document): array
    {
        $found = [];

        foreach ($document->children() as $response) {
            $found[] = $response->child('{' . self::NS . '}request-status')?->text() ?? '';
        }

        return $found;
    }

    /**
     * Cyrus's Outbox, three calendar users with calendars, and one calendar
     * that says it is transparent.
     */
    private function server(bool $directory = true, int $atMost = 5_000): Server
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES);
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Outbox::of(new MemoryCollection('outbox')))
                        ->add(Inbox::of(new MemoryCollection('inbox')))
                        ->add(Calendar::of(self::aCalendarCalled(
                            'work',
                            ['20090603T140000Z', '20090603T150000Z'],
                        ))))
                    ->add((new MemoryCollection('wilfredo'))
                        ->add(Calendar::of(self::aCalendarCalled(
                            'work',
                            ['20090602T110000Z', '20090602T120000Z'],
                            ['20090603T080000Z', '20090603T090000Z'],
                        ))))
                    ->add((new MemoryCollection('bernard'))
                        // **The transparent one first.** §9.1 has it
                        // contribute nothing — and a guard that broke out of
                        // the walk instead of passing over would lose the
                        // opaque one behind it.
                        ->add(Calendar::of(self::aCalendarCalled(
                            'hidden',
                            ['20090603T090000Z', '20090603T100000Z'],
                        )))
                        ->add(Calendar::of(self::aCalendarCalled(
                            'work',
                            ['20090602T150000Z', '20090602T160000Z'],
                        )))))
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))
                    ->add(new Principal(new PrincipalInfo('wilfredo', 'Wilfredo')))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))),
        );

        $server = new Server($tree, $events, new Writer(['DAV:' => 'd', self::NS => 'C']));
        $post = new Post($server);

        (new Scheduling($server, $directory ? $users : null, atMost: $atMost))->register($post);
        $server->onMethod('POST', $post(...));

        return $server;
    }

    /**
     * A calendar collection holding one calendar object resource per hour
     * given — more than one where a case needs the server to count past the
     * first.
     *
     * @param array{string, string} ...$hours
     */
    private static function aCalendarCalled(string $name, array ...$hours): MemoryCollection
    {
        $calendar = new MemoryCollection($name);

        foreach ($hours as $number => [$from, $until]) {
            $calendar->add(new MemoryFile(
                $number . '.ics',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
                . 'BEGIN:VEVENT' . "\r\nUID:" . $name . '-' . $number . "@example.com\r\n"
                . "DTSTAMP:20090601T000000Z\r\nDTSTART:" . $from . "\r\nDTEND:" . $until
                . "\r\nSUMMARY:An hour\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            ));
        }

        return $calendar;
    }

    /**
     * The body with iCalendar's line folding undone, so that a property can
     * be looked for as it was written. RFC 5545 §3.1 folds at 75 octets, and
     * a `PRODID` or an `ORGANIZER` is easily longer than that.
     */
    private static function unfolded(string $body): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $body);
    }

    /**
     * The three properties this plugin reads for §5, answered the way a
     * deployment answers them.
     *
     * `DAV:owner` on the Outbox is what §5.2.2 compares against;
     * `CALDAV:calendar-home-set` on a principal is where their calendars are;
     * and `CALDAV:schedule-calendar-transp` is §9.1's, said here only of the
     * one calendar that is transparent — every other answers nothing, which
     * is the case §9.1's default is for.
     */
    private static function answer(PropertiesRequested $event, MemoryCalendarUserBackend $users): void
    {
        $result = $event->result();
        $segments = array_values(array_filter(explode('/', $result->path()), static fn (string $part): bool => $part !== ''));

        if (($segments[0] ?? '') === 'principals') {
            if ($result->wants(self::HOME_SET)) {
                $result->set(
                    self::HOME_SET,
                    self::naming(self::HOME_SET, '/calendars/' . ($segments[1] ?? '') . '/'),
                );
            }

            // §2.4.1's own property, which `Plugin\CalDav` answers from the
            // same directory in a deployment — one table read two ways, so
            // that the fixture cannot disagree with itself.
            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(
                    self::ADDRESS_SET,
                    self::naming(self::ADDRESS_SET, ...$users->addressesOf($segments[1] ?? '')),
                );
            }

            return;
        }

        if ($result->wants(self::OWNER) && ($segments[0] ?? '') === 'calendars' && count($segments) === 3) {
            $result->set(self::OWNER, self::naming(self::OWNER, '/principals/' . $segments[1] . '/'));

            return;
        }

        if ($result->wants(self::TRANSP) && $result->path() === 'calendars/bernard/hidden') {
            $transparent = new Element(self::TRANSP);

            $transparent->append(new Element('{' . self::NS . '}transparent'));
            $result->set(self::TRANSP, $transparent);
        }
    }

    /**
     * A property holding hrefs — one for a home, however many a calendar
     * user has addresses.
     */
    private static function naming(string $property, string ...$hrefs): Element
    {
        $element = new Element($property);

        foreach ($hrefs as $href) {
            $inside = new Element('{DAV:}href');

            $inside->appendText($href);
            $element->append($inside);
        }

        return $element;
    }
}
