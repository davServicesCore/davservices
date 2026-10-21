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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Limits;
use DavServices\CalDav\TimeRange;
use DavServices\Exception\BadRequest;
use DavServices\Exception\DavException;
use DavServices\VObject\Component;
use DavServices\VObject\Recur\Iterator;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the limits of RFC 4791 §5.2, as §5.3.2.1 enforces them.
 * R-CAL-02, R-CAL-03, R-CAL-08, R-CAL-09. P6-02.
 *
 * **Each limit is a property and a precondition in one.** §5.2.5 to §5.2.9
 * define the properties and each says the same thing about breaking them —
 * "MUST result in an error, with the CALDAV:… precondition (Section 5.3.2.1)
 * being violated" — which is R-CAL-09 in the memo's own words: a limit reached
 * is a refusal that names itself, never an abort.
 *
 * The defaults are the ones the requirements fix: 10 MB, 1900 to 2099, 5.000
 * instances, 250 attendees per instance. R-CAL-08 makes every one of them
 * configurable.
 *
 * ## The comparisons, and the one the memo contradicts itself about
 *
 * §5.3.2.1 spells out each test, and they are not all the same shape:
 * `max-resource-size` is "less than or equal to", `min-date-time` is "greater
 * than or equal to", `max-instances` and `max-attendees-per-instance` are
 * "less than or equal to" — and `max-date-time` is "**less than**".
 *
 * But §5.2.7 calls that same value "the **inclusive** latest date that the
 * server is willing to accept", and §5.2.6 calls its counterpart "the earliest
 * **inclusive** date". So the two sections disagree about one boundary, and
 * this library takes §5.2.7's reading: the property is what a client reads
 * before deciding what to send, and a server that refused the value its own
 * property called acceptable would break the promise the client acted on.
 */
#[CoversClass(Limits::class)]
final class LimitsTest extends TestCase
{
    /**
     * **Nothing in the way is nothing to say.** An object a server will have
     * passes every one of the six without a word.
     */
    public function testAnObjectWithinEveryLimitIsTaken(): void
    {
        $this->limits()->mustTake(...$this->of(self::event()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * §5.3.2.1: the resource "MUST have an octet size less than or equal to
     * the value of the CALDAV:max-resource-size property value". So the limit
     * itself still fits, and `413` is the status HTTP has for a body too large.
     */
    public function testRefusesAnObjectLargerThanTheLimit(): void
    {
        $this->expectRefusal('max-resource-size', 413, new Limits(maxResourceSize: 10));
    }

    public function testAnObjectOfExactlyTheLimitIsTaken(): void
    {
        [$bytes, $object, $kinds] = $this->of(self::event());

        (new Limits(maxResourceSize: strlen($bytes)))->mustTake($bytes, $object, $kinds);

        $this->expectNotToPerformAssertions();
    }

    /**
     * §5.2.4: "In the absence of this property, the server MUST only accept
     * data with the media type 'text/calendar' and iCalendar version 2.0" — so
     * another version is an unsupported media type, and `415` is the status
     * for one.
     */
    public function testRefusesAVersionItNeverAdvertised(): void
    {
        $object = CalendarObject::in(str_replace("VERSION:2.0\r\n", "VERSION:1.0\r\n", self::wrapping(self::event())));

        self::assertNotNull($object);

        $this->expectCode(415, 'supported-calendar-data');
        $this->limits()->mustTake('irrelevant', $object, Limits::KINDS);
    }

    /**
     * An object with no `VERSION` at all is not iCalendar 2.0 either —
     * RFC 5545 §3.6 makes the property mandatory in a `VCALENDAR`.
     */
    public function testRefusesAnObjectWithoutAVersion(): void
    {
        $object = CalendarObject::in(str_replace("VERSION:2.0\r\n", '', self::wrapping(self::event())));

        self::assertNotNull($object);

        $this->expectCode(415, 'supported-calendar-data');
        $this->limits()->mustTake('irrelevant', $object, Limits::KINDS);
    }

    /**
     * §5.3.2.1's `CALDAV:supported-calendar-component`: the resource "MUST
     * contain a type of calendar component that is supported in the targeted
     * calendar collection". Note the precondition's name is the singular one,
     * not the property's.
     */
    public function testRefusesAKindTheCollectionDoesNotTake(): void
    {
        [$bytes, $object] = $this->of(self::event());

        $this->expectCode(403, 'supported-calendar-component');
        $this->limits()->mustTake($bytes, $object, ['VTODO']);
    }

    /**
     * And the kinds a collection takes are the collection's, not the
     * server's: §5.2.3 lets a client narrow them per calendar with
     * `MKCALENDAR`.
     */
    public function testAKindTheCollectionDoesTakeIsTaken(): void
    {
        [$bytes, $object] = $this->of(self::event());

        $this->limits()->mustTake($bytes, $object, ['VEVENT', 'VTODO']);

        $this->expectNotToPerformAssertions();
    }

    /**
     * §5.3.2.1: "MUST have a number of ATTENDEE properties on any one instance
     * less than or equal to the value of the
     * CALDAV:max-attendees-per-instance property value".
     *
     * **"On any one instance"**, so it is counted per component rather than
     * over the object: two components with two attendees each are not four.
     */
    public function testRefusesMoreAttendeesOnOneInstanceThanTheLimit(): void
    {
        $crowded = self::event("ATTENDEE:mailto:a@example.com\r\nATTENDEE:mailto:b@example.com\r\n");

        $this->expectCode(403, 'max-attendees-per-instance');
        $this->limits(new Limits(maxAttendees: 1))->mustTake(...$this->of($crowded));
    }

    public function testExactlyTheAttendeeLimitIsTaken(): void
    {
        $crowded = self::event("ATTENDEE:mailto:a@example.com\r\nATTENDEE:mailto:b@example.com\r\n");

        (new Limits(maxAttendees: 2))->mustTake(...$this->of($crowded));

        $this->expectNotToPerformAssertions();
    }

    public function testAttendeesAreCountedPerComponentRatherThanPerObject(): void
    {
        $two = self::event("ATTENDEE:mailto:a@example.com\r\n")
            . self::event("ATTENDEE:mailto:b@example.com\r\n", '2@example.com');

        (new Limits(maxAttendees: 1))->mustTake(...$this->of($two));

        $this->expectNotToPerformAssertions();
    }

    /**
     * §5.3.2.1: "MUST have all of its iCalendar DATE or DATE-TIME property
     * values […] greater than or equal to the value of the
     * CALDAV:min-date-time property value".
     *
     * @param string $property The date-carrying property the case is about
     */
    #[DataProvider('tooEarly')]
    public function testRefusesADateEarlierThanTheLimit(string $property, string $line): void
    {
        $this->expectCode(403, 'min-date-time');
        $this->limits()->mustTake(...$this->of(self::event($line)));
    }

    /**
     * **One case per date-carrying property**, because a walk that looked at
     * `DTSTART` alone would pass everything else — and §5.3.2.1 says "all of
     * its iCalendar DATE or DATE-TIME property values".
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tooEarly(): iterable
    {
        yield 'DTSTART' => ['DTSTART', "DTSTART:18991231T120000Z\r\n"];
        yield 'DTEND' => ['DTEND', "DTEND:18991231T130000Z\r\n"];
        yield 'DTSTAMP' => ['DTSTAMP', "DTSTAMP:18991231T120000Z\r\n"];
        yield 'CREATED' => ['CREATED', "CREATED:18991231T120000Z\r\n"];
        yield 'LAST-MODIFIED' => ['LAST-MODIFIED', "LAST-MODIFIED:18991231T120000Z\r\n"];
        yield 'RECURRENCE-ID' => ['RECURRENCE-ID', "RECURRENCE-ID:18991231T120000Z\r\n"];
        yield 'EXDATE' => ['EXDATE', "EXDATE:18991231T120000Z\r\n"];
        yield 'RDATE' => ['RDATE', "RDATE:18991231T120000Z\r\n"];
        yield 'a date rather than a date-time' => ['DTSTART', "DTSTART;VALUE=DATE:18991231\r\n"];
        yield 'one of a list' => ['RDATE', "RDATE:20260101T120000Z,18991231T120000Z\r\n"];

        // "All of its" reaches inside: a `TRIGGER` whose default type is a
        // DURATION (RFC 5545 §3.8.6.3) becomes a date when the `VALUE`
        // parameter says so, and it sits one component deeper.
        yield 'inside an alarm' => [
            'TRIGGER',
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\n"
                . "TRIGGER;VALUE=DATE-TIME:18991231T120000Z\r\nEND:VALARM\r\n",
        ];
    }

    /**
     * §5.3.2.1 writes `max-date-time` as "less than", and §5.2.7 calls the
     * same value "the inclusive latest date that the server is willing to
     * accept". **This is the boundary the two sections disagree about**, and
     * the property's own word wins: a client reads `max-date-time` to decide
     * what to send, and refusing exactly what it was told it could send would
     * break the promise it acted on.
     */
    public function testExactlyTheLatestMomentIsStillTaken(): void
    {
        $this->limits()->mustTake(...$this->of(self::event("DTSTART:20991231T235959Z\r\n")));

        $this->expectNotToPerformAssertions();
    }

    public function testRefusesADateLaterThanTheLimit(): void
    {
        $this->expectCode(403, 'max-date-time');
        $this->limits()->mustTake(...$this->of(self::event("DTSTART:21000101T000000Z\r\n")));
    }

    public function testExactlyTheEarliestMomentIsStillTaken(): void
    {
        $this->limits()->mustTake(...$this->of(self::event("DTSTART:19000101T000000Z\r\n")));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **An alarm that goes off relative to its event is left alone.**
     * RFC 5545 §3.8.6.3 makes `DURATION` the default value type of a
     * `TRIGGER`, so "-PT15M" is no date at all — and a walk that read every
     * property as one would refuse an event for its reminder.
     */
    public function testARelativeTriggerIsNoDate(): void
    {
        $reminder = self::event("BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:x\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n");

        $this->limits()->mustTake(...$this->of($reminder));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A time zone's own observances are not held to the two dates**, and
     * both memos say why between them.
     *
     * RFC 5545 §3.6.5: "The mandatory 'DTSTART' property gives the effective
     * onset date and local time for the time zone sub-component definition.
     * 'DTSTART' in this usage MUST be specified as a date with a local time
     * value." RFC 4791 §5.2.6 and §5.2.7 both put their limit "in UTC" — and
     * the offset that would turn that local time into a UTC one is the very
     * thing the component is defining. There is nothing to compare, so no
     * comparison is made.
     *
     * The case is not academic: Exchange writes its observances with
     * `DTSTART:16010101T020000`, which a 1900 floor would refuse — over a
     * value that says nothing about when anything is happening. The time
     * zone's own properties are compared like any others; `LAST-MODIFIED` is
     * a UTC value.
     */
    public function testATimeZonesOwnObservancesAreNotHeldToTheDates(): void
    {
        $zone = "BEGIN:VTIMEZONE\r\nTZID:W. Europe Standard Time\r\nLAST-MODIFIED:20260101T000000Z\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:16010101T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

        $this->limits()->mustTake(...$this->of($zone . self::event()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A value that is no date is no date limit's business.** `SUMMARY` and
     * `UID` can hold anything, including something that looks like a
     * timestamp, and a walk that parsed every property would refuse an event
     * for the text of its title.
     */
    public function testAValueThatIsNoDateIsLeftAlone(): void
    {
        $odd = self::event("SUMMARY:18991231T120000Z\r\n");

        $this->limits()->mustTake(...$this->of($odd));

        $this->expectNotToPerformAssertions();
    }

    /**
     * A date this library cannot read is not a limit's business either: it is
     * invalid iCalendar, which `CALDAV:valid-calendar-data` is for, and
     * refusing it here would name the wrong precondition.
     */
    public function testADateItCannotReadIsNoLimitsBusiness(): void
    {
        $this->limits()->mustTake(...$this->of(self::event("DTEND:not-a-date\r\n")));

        $this->expectNotToPerformAssertions();
    }

    /**
     * §5.3.2.1: "MUST generate a number of recurring instances less than or
     * equal to the value of the CALDAV:max-instances property value".
     */
    public function testRefusesASeriesWithMoreInstancesThanTheLimit(): void
    {
        $weekly = self::event("DTSTART:20260105T120000Z\r\nRRULE:FREQ=WEEKLY;COUNT=10\r\n");

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 9))->mustTake(...$this->of($weekly));
    }

    public function testExactlyTheInstanceLimitIsTaken(): void
    {
        $weekly = self::event("DTSTART:20260105T120000Z\r\nRRULE:FREQ=WEEKLY;COUNT=10\r\n");

        (new Limits(maxInstances: 10))->mustTake(...$this->of($weekly));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A series with no end is the case the limit exists for.** An
     * unbounded `RRULE` generates for ever, so counting has to stop — and
     * stopping is the refusal.
     */
    public function testRefusesASeriesWithNoEnd(): void
    {
        $forever = self::event("DTSTART:20260105T120000Z\r\nRRULE:FREQ=DAILY\r\n");

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 20))->mustTake(...$this->of($forever));
    }

    /**
     * An object of overrides alone generates one instance each — §4.1 allows
     * a resource that "just contain[s] components that represent 'overridden'
     * instances" — so the count is of components rather than of a series.
     */
    public function testAnObjectOfOverridesAloneIsCountedToo(): void
    {
        $overrides = self::event("DTSTART:20260105T120000Z\r\nRECURRENCE-ID:20260105T120000Z\r\n")
            . self::event("DTSTART:20260112T120000Z\r\nRECURRENCE-ID:20260112T120000Z\r\n");

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 1))->mustTake(...$this->of($overrides));
    }

    /**
     * A component with nothing to expand is one instance, and a `VTIMEZONE`
     * is none at all — it is not a thing that happens.
     */
    public function testATimeZoneIsNoInstance(): void
    {
        $zone = "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";

        (new Limits(maxInstances: 1))->mustTake(...$this->of($zone . self::event()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A series this library cannot expand is no limit's business.** A
     * malformed `RRULE` is invalid iCalendar, which is
     * `CALDAV:valid-calendar-data`'s to refuse — naming `max-instances` for it
     * would send a client looking at the wrong number.
     */
    public function testASeriesItCannotExpandIsNoLimitsBusiness(): void
    {
        $broken = self::event("DTSTART:20260105T120000Z\r\nRRULE:FREQ=NEVERLY\r\n");

        $this->limits()->mustTake(...$this->of($broken));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A limit set above the iterator's own stop is still a refusal.**
     * R-RRULE-04 gives the expansion a ceiling of its own, so a deployment
     * that raises `max-instances` past it meets that one first — and a client
     * reading `CALDAV:max-instances` has to get the same answer either way,
     * or the number would stop meaning anything above ten thousand.
     */
    public function testALimitAboveTheIteratorsOwnStopIsStillMaxInstances(): void
    {
        $forever = self::event("DTSTART:20260105T120000Z\r\nRRULE:FREQ=DAILY\r\n");

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: Iterator::ITERATIONS * 2))->mustTake(...$this->of($forever));
    }

    /**
     * **Every limit this publishes is the limit it was given**, which is what
     * R-CAL-08 asks for: §5.2.5 to §5.2.9 each let a server pick its own
     * number, and one whose properties said something its guard did not do
     * would be publishing a promise it does not keep.
     */
    public function testItPublishesTheLimitsItWasGiven(): void
    {
        $limits = new Limits(
            maxResourceSize: 1_024,
            earliest: '19700101T000000Z',
            latest: '20380119T031407Z',
            maxInstances: 7,
            maxAttendees: 3,
            kinds: ['VTODO'],
        );

        self::assertSame(1_024, $limits->maxResourceSize());
        self::assertSame('19700101T000000Z', $limits->earliest());
        self::assertSame('20380119T031407Z', $limits->latest());
        self::assertSame(7, $limits->maxInstances());
        self::assertSame(3, $limits->maxAttendees());
        self::assertSame(['VTODO'], $limits->kinds());
    }

    /**
     * **And a server nobody configured publishes the numbers written here.**
     * The memo sets none of them — §5.2.5 to §5.2.9 say what each property
     * means and leave the figure to the server — so the defaults are this
     * library's answer and a test is where that answer is pinned down. The two
     * dates are the ones §5.2.6 and §5.2.7 are for: a span wide enough that no
     * real calendar reaches either end, and narrow enough that a year nobody
     * can represent cannot be stored.
     */
    public function testTheDefaultsArePinnedDown(): void
    {
        $limits = new Limits();

        self::assertSame(10_485_760, $limits->maxResourceSize());
        self::assertSame('19000101T000000Z', $limits->earliest());
        self::assertSame('20991231T235959Z', $limits->latest());
        self::assertSame(5_000, $limits->maxInstances());
        self::assertSame(250, $limits->maxAttendees());
        self::assertSame(['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'], $limits->kinds());
    }
    /**
     * **Every kind is judged, not just the first.** §5.3.2.1 holds a resource
     * to the kinds "supported in the targeted calendar collection", and a walk
     * that stopped at the first acceptable one would take an object for the
     * company it keeps.
     *
     * §4.1 forbids more than one kind in one resource, and `CALDAV:valid-
     * calendar-object-resource` is what refuses that (P6-04) — which does not
     * excuse this check from looking at everything it is handed.
     */
    public function testEveryKindIsJudgedAndNotJustTheFirst(): void
    {
        $both = self::event() . "BEGIN:VTODO\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\n";
        [$bytes, $object] = $this->of($both);

        $this->expectCode(403, 'supported-calendar-component');
        $this->limits()->mustTake($bytes, $object, ['VEVENT']);
    }

    /**
     * **And every instance is counted, not just the first.** §5.2.9 is about
     * "the maximum number of ATTENDEE properties in any instance", so a walk
     * that stopped at an instance it liked would let the next one carry a
     * crowd.
     */
    public function testEveryInstanceIsCountedAndNotJustTheFirst(): void
    {
        $quiet = self::event("ATTENDEE:mailto:a@example.com\r\n");
        $crowded = self::event(
            "ATTENDEE:mailto:b@example.com\r\nATTENDEE:mailto:c@example.com\r\nATTENDEE:mailto:d@example.com\r\n",
            '2@example.com',
        );

        $this->expectCode(403, 'max-attendees-per-instance');
        $this->limits(new Limits(maxAttendees: 2))->mustTake(...$this->of($quiet . $crowded));
    }

    /**
     * **A component without a `UID` does not stop the walk.** A `VTIMEZONE`
     * has none — RFC 5545 §3.6.5 gives it a `TZID` instead — and it is the
     * component that comes first in almost every object a client sends. A walk
     * that gave up there would never reach the event behind it.
     */
    public function testAComponentWithoutAUidDoesNotStopTheWalk(): void
    {
        $zone = "BEGIN:VTIMEZONE\r\nTZID:Etc/UTC\r\nBEGIN:STANDARD\r\nDTSTART:19701101T000000\r\n"
            . "TZOFFSETFROM:+0000\r\nTZOFFSETTO:+0000\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        $forever = self::event("RRULE:FREQ=DAILY\r\n");

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 20))->mustTake(...$this->of($zone . $forever));
    }

    /**
     * **And every `UID` is expanded, not just the first.** One object may
     * carry several series — §3.6.1 keeps one `UID` per series — and §5.3.2.1
     * asks what "the resource submitted" generates, which is all of them.
     */
    public function testEveryUidIsExpandedAndNotJustTheFirst(): void
    {
        $once = self::event();
        $forever = self::event("RRULE:FREQ=DAILY\r\n", '2@example.com');

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 20))->mustTake(...$this->of($once . $forever));
    }

    /**
     * **A series after one it cannot read is still judged.** An `RRULE` this
     * library cannot expand is invalid iCalendar, which
     * `CALDAV:valid-calendar-data` refuses — and giving up on the rest of the
     * object there would let the next series run for ever behind it.
     */
    public function testASeriesAfterOneItCannotReadIsStillJudged(): void
    {
        $broken = self::event("RRULE:FREQ=NEVERLY\r\n");
        $forever = self::event("RRULE:FREQ=DAILY\r\n", '2@example.com');

        $this->expectCode(403, 'max-instances');
        $this->limits(new Limits(maxInstances: 20))->mustTake(...$this->of($broken . $forever));
    }

    /**
     * **A property that says it is text is text**, however much its value
     * looks like a timestamp. RFC 5545 §3.2.20 lets the `VALUE` parameter
     * override the default type in either direction, and §5.3.2.1 is about
     * "DATE or DATE-TIME property values" — a description is neither.
     */
    public function testAPropertyThatSaysItIsTextIsLeftAlone(): void
    {
        $this->limits()->mustTake(...$this->of(self::event("DESCRIPTION;VALUE=TEXT:18991231T120000Z\r\n")));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **An object written in lower case is the same object.** RFC 5545 §3.1
     * reads "the property name `DUE` is the same as `due` and `Due`", and
     * RFC 5234 §2.3 makes the `BEGIN:VCALENDAR` of §3.4's grammar
     * case-insensitive too: "ABNF strings are case insensitive". So a limit
     * that only recognised the shouted spelling would take whatever a quiet
     * client sent.
     *
     * @param string $object The object the case is about
     */
    #[DataProvider('quietlyOutOfRange')]
    public function testALowerCaseObjectIsHeldToTheSameLimits(string $object): void
    {
        $this->expectCode(403, 'min-date-time');
        $this->limits()->mustTake(...$this->of($object));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quietlyOutOfRange(): iterable
    {
        yield 'the property name' => [
            "begin:vevent\r\nuid:1@example.com\r\ndtstamp:20260101T000000Z\r\n"
                . "dtstart:18991231T120000Z\r\nend:vevent\r\n",
        ];

        yield 'the VALUE parameter' => [
            "begin:vevent\r\nuid:1@example.com\r\ndtstamp:20260101T000000Z\r\ndtstart:20260105T120000Z\r\n"
                . "begin:valarm\r\naction:display\r\ndescription:x\r\n"
                . "trigger;value=date-time:18991231T120000Z\r\nend:valarm\r\nend:vevent\r\n",
        ];
    }

    /**
     * And the exemption of a time zone's observances holds in lower case too,
     * or the one component the rule is about would lose it by being written
     * quietly.
     */
    public function testALowerCaseTimeZoneKeepsItsExemption(): void
    {
        $zone = "begin:vtimezone\r\ntzid:W. Europe Standard Time\r\n"
            . "begin:standard\r\ndtstart:16010101T030000\r\ntzoffsetfrom:+0200\r\ntzoffsetto:+0100\r\n"
            . "end:standard\r\nend:vtimezone\r\n";

        $this->limits()->mustTake(...$this->of($zone . self::event()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **The same two limits, asked of a question instead of an object.** §7.8
     * lists them among the preconditions of `calendar-query`, and says of each
     * one what §5.3.2.1 says of a resource:
     *
     * > (CALDAV:min-date-time): Any XML element specifying a range of time
     * > MUST have its start or end DATE or DATE-TIME values greater than or
     * > equal to the value of the CALDAV:min-date-time property value
     * > (Section 5.2.6) on the calendar collections being targeted by the
     * > REPORT request;
     *
     * So a client asking what happened in 1898 is asking outside what this
     * server keeps, and the answer is the precondition rather than an empty
     * result that looks like an answer.
     *
     * @param string|null $from What the range's `start` attribute says
     * @param string|null $until What its `end` attribute says
     * @param string $precondition The precondition the range violates
     */
    #[DataProvider('rangesOutsideWhatItKeeps')]
    public function testRefusesARangeOutsideTheDatesItKeeps(
        ?string $from,
        ?string $until,
        string $precondition,
    ): void {
        $this->expectCode(403, $precondition);
        $this->limits()->mustTakeTheRange($this->range($from, $until));
    }

    /**
     * **Both ends, and each on its own**, because §7.8 says "its start or end"
     * — a check that looked at the start alone would take a query that ran to
     * the year 2600.
     *
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function rangesOutsideWhatItKeeps(): iterable
    {
        yield 'a start before the earliest' => ['18981231T120000Z', '20260105T130000Z', 'min-date-time'];
        yield 'an end before the earliest' => [null, '18991231T120000Z', 'min-date-time'];
        yield 'an end after the latest' => ['20260105T120000Z', '21000101T000000Z', 'max-date-time'];
        yield 'a start after the latest' => ['21000101T000000Z', null, 'max-date-time'];
    }

    /**
     * And a range inside them is a question this server can answer, with the
     * open end of §9.9 — "assume 'any time in the past' or 'any time in the
     * future'" — meaning no limit to break rather than a limit broken.
     *
     * @param string|null $from What the range's `start` attribute says
     * @param string|null $until What its `end` attribute says
     */
    #[DataProvider('rangesItTakes')]
    public function testTakesARangeWithinTheDatesItKeeps(?string $from, ?string $until): void
    {
        $this->limits()->mustTakeTheRange($this->range($from, $until));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function rangesItTakes(): iterable
    {
        yield 'both ends inside' => ['20260105T120000Z', '20260105T130000Z'];
        yield 'open at the start' => [null, '20260105T130000Z'];
        yield 'open at the end' => ['20260105T120000Z', null];

        // "greater than or equal to", and "less than or equal to": the limits
        // themselves are inside what the server keeps.
        yield 'exactly the earliest' => [Limits::EARLIEST, '20260105T130000Z'];
        yield 'exactly the latest' => ['20260105T120000Z', Limits::LATEST];
    }

    /**
     * **Every element of a request body that names a range is held to the
     * dates.** §7.8 and §7.9 say it in the same words — "Any XML element
     * specifying a range of time MUST have its start or end DATE or DATE-TIME
     * values greater than or equal to the value of the CALDAV:min-date-time
     * property value" — and RFC 4791 has four such elements: §9.9's
     * `time-range` inside a filter, and the three of §9.6 that say what of an
     * object comes back (§9.6.5 to §9.6.7).
     *
     * A walk that knew only the first would take a query the memo refuses,
     * which is exactly what an `expand` over the next two centuries is.
     *
     * @param string $body The request body, as a client writes it
     * @param string $precondition The precondition it violates
     */
    #[DataProvider('bodiesNamingATimeItKeepsNothingFrom')]
    public function testRefusesEveryRangeInABodyThatLiesOutsideTheDatesItKeeps(
        string $body,
        string $precondition,
    ): void {
        $this->expectCode(403, $precondition);
        $this->limits()->mustTakeTheRangesIn((new Reader())->parse($body));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesNamingATimeItKeepsNothingFrom(): iterable
    {
        yield 'a time-range in a filter' => [
            self::query('<filter><comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<time-range start="18900101T000000Z" end="18900102T000000Z"/>'
                . '</comp-filter></comp-filter></filter>'),
            'min-date-time',
        ];

        yield 'a time-range inside a prop-filter' => [
            self::query('<filter><comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<prop-filter name="DTSTART">'
                . '<time-range start="21000101T000000Z" end="21000102T000000Z"/>'
                . '</prop-filter></comp-filter></comp-filter></filter>'),
            'max-date-time',
        ];

        yield 'an expansion' => [
            self::query('<D:prop xmlns:D="DAV:"><calendar-data>'
                . '<expand start="18900101T000000Z" end="18900102T000000Z"/>'
                . '</calendar-data></D:prop>'),
            'min-date-time',
        ];

        yield 'a limit on the recurrences' => [
            self::query('<D:prop xmlns:D="DAV:"><calendar-data>'
                . '<limit-recurrence-set start="21000101T000000Z" end="21000102T000000Z"/>'
                . '</calendar-data></D:prop>'),
            'max-date-time',
        ];

        // **Every child of the body, not the first of them.** A
        // `calendar-query` carries its property group *and* its filter, and a
        // walk that stopped after the first child it stepped into would take a
        // range the memo refuses.
        yield 'a range in the second child of the body' => [
            self::query('<D:prop xmlns:D="DAV:"><calendar-data>'
                . '<expand start="20260101T000000Z" end="20270101T000000Z"/>'
                . '</calendar-data></D:prop>'
                . '<filter><comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<time-range start="18900101T000000Z" end="18900102T000000Z"/>'
                . '</comp-filter></comp-filter></filter>'),
            'min-date-time',
        ];

        yield 'a limit on the free/busy' => [
            self::query('<D:prop xmlns:D="DAV:"><calendar-data>'
                . '<limit-freebusy-set start="18900101T000000Z" end="18900102T000000Z"/>'
                . '</calendar-data></D:prop>'),
            'min-date-time',
        ];
    }

    /**
     * And a body whose ranges all lie inside them is a request this server can
     * answer — whichever of the four elements they are written in.
     */
    public function testTakesABodyWhoseRangesLieInsideTheDatesItKeeps(): void
    {
        $this->limits()->mustTakeTheRangesIn((new Reader())->parse(self::query(
            '<D:prop xmlns:D="DAV:"><calendar-data>'
            . '<expand start="20260101T000000Z" end="20270101T000000Z"/>'
            . '</calendar-data></D:prop>'
            . '<filter><comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
            . '<time-range start="20260105T120000Z" end="20260105T130000Z"/>'
            . '</comp-filter></comp-filter></filter>',
        )));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **The three elements of §9.6 want both ends**, and that is read here as
     * well: a limit open at one end limits nothing, and §9.6.5 to §9.6.7 make
     * both attributes `#REQUIRED`.
     */
    public function testRefusesALimitThatNamesOnlyOneEnd(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('#REQUIRED');

        $this->limits()->mustTakeTheRangesIn((new Reader())->parse(self::query(
            '<D:prop xmlns:D="DAV:"><calendar-data>'
            . '<limit-recurrence-set start="20260101T000000Z"/>'
            . '</calendar-data></D:prop>',
        )));
    }

    /**
     * One request body, with the namespaces a client writes.
     */
    private static function query(string $children): string
    {
        return sprintf(
            '<calendar-query xmlns="urn:ietf:params:xml:ns:caldav">%s</calendar-query>',
            $children,
        );
    }

    /**
     * One range, from what the attributes say; null leaves an attribute out.
     */
    private function range(?string $from, ?string $until): TimeRange
    {
        $attributes = [];

        if ($from !== null) {
            $attributes['start'] = $from;
        }

        if ($until !== null) {
            $attributes['end'] = $until;
        }

        return TimeRange::of(new Element(TimeRange::NAME, $attributes));
    }

    private function limits(?Limits $limits = null): Limits
    {
        return $limits ?? new Limits();
    }

    /**
     * The three arguments `mustTake()` takes, for one object.
     *
     * @return array{string, Component, list<string>}
     */
    private function of(string $components): array
    {
        $bytes = self::wrapping($components);

        // `fail()` rather than an assertion, so that a case which expects no
        // assertions at all is not made to perform one by its own fixture.
        $object = CalendarObject::in($bytes)
            ?? self::fail('The case built something that is no calendar object.');

        return [$bytes, $object, Limits::KINDS];
    }

    private static function wrapping(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }

    /**
     * One `VEVENT` within every limit, plus whatever a case adds to it.
     */
    private static function event(string $lines = '', string $uid = '1@example.com'): string
    {
        return sprintf(
            "BEGIN:VEVENT\r\nUID:%s\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T120000Z\r\n%sEND:VEVENT\r\n",
            $uid,
            $lines,
        );
    }

    private function expectRefusal(string $precondition, int $status, Limits $limits): void
    {
        $this->expectCode($status, $precondition);
        $limits->mustTake(...$this->of(self::event()));
    }

    private function expectCode(int $status, string $precondition): void
    {
        $this->expectException(DavException::class);
        $this->expectExceptionCode($status);
        $this->expectExceptionMessage($precondition);
    }
}
