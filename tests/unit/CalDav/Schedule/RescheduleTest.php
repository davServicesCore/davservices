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
use DavServices\CalDav\Schedule\Reschedule;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the answers a reschedule takes back (RFC 6638 §3.2.8).
 * R-CAL-32, P8-02j.
 *
 * > A reschedule occurs when any "DTSTART", "DTEND", "DURATION", "DUE",
 * > "RRULE", "RDATE", or "EXDATE" property changes in a calendar component
 * > such that existing recurrence instances are impacted by the changes, as
 * > shown in the table below. Servers MUST reset the "PARTSTAT" property
 * > parameter value of all "ATTENDEE" properties, except the one that
 * > corresponds to the "Organizer", to "NEEDS-ACTION" for each calendar
 * > component change that causes any instance to be rescheduled.
 *
 * ## The table is two rules, not four
 *
 * The first row stands by itself:
 *
 * > DTSTART, DTEND, DURATION, DUE: Any change to these properties results in
 * > "PARTSTAT" being set to "NEEDS-ACTION".
 *
 * **Any change**, with no condition on what it does to the recurrence set —
 * which is why a `DTEND` that moves counts although it generates nothing.
 *
 * The other three rows name a condition each, and the three conditions are
 * one question:
 *
 * > RRULE: A change to or addition of this property that results in the
 * > addition of new recurring instances or a change in time for existing
 * > recurring instances results in "PARTSTAT" being reset to "NEEDS-ACTION"
 * > on each affected component.
 *
 * > RDATE: [the same sentence]
 *
 * > EXDATE: A change to or removal of this property that results in the
 * > reinstatement of recurring instances results in "PARTSTAT" being set to
 * > "NEEDS-ACTION" on each affected component.
 *
 * An instance added, an instance whose time changed, an instance reinstated:
 * each of the three is an identifier the expanded set answers for now and
 * answers for differently, or not at all, before. RFC 5546 §2.1.5 makes `UID`
 * and `RECURRENCE-ID` "the primary key" of an instance, so the question this
 * asks of two revisions is: **does the set after hold an identifier the set
 * before did not, or hold one at a different time?** An instance merely
 * dropped is in none of the three rows, and so takes nobody's answer away.
 *
 * ## Two attendees it does not touch
 *
 * > This section specifies additional requirements on the handling of the
 * > "PARTSTAT" property parameter when the "SCHEDULE-AGENT" property
 * > parameter on the corresponding "ATTENDEE" property is set to the value
 * > "SERVER" or is not present.
 *
 * The section's own first sentence: an attendee whose `SCHEDULE-AGENT` is
 * `CLIENT` or `NONE` is somebody else's to tell, so their answer is not the
 * server's to take back. And "except the one that corresponds to the
 * 'Organizer'" leaves the organizer's own participation where it stands.
 */
#[CoversClass(Reschedule::class)]
final class RescheduleTest extends TestCase
{
    private const BERNARD = 'mailto:bernard@example.com';

    private const CYRUS = 'mailto:cyrus@example.com';

    private const ERIC = 'mailto:eric@example.com';

    private const ORGANISED = 'ORGANIZER:' . self::BERNARD;

    /** What §2.4.1 makes the organizer here: every address of theirs. */
    private const OWNERS = [self::BERNARD, 'urn:uuid:bernard'];

    private const ACCEPTED = 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS;

    private const RESET = 'ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS;

    private const DAILY = 'RRULE:FREQ=DAILY;COUNT=3';

    /**
     * A rule that names a day that never comes, as {@see
     * \DavServices\CalDav\Limits} puts it: the expansion gives up on it, and
     * so the properties are read instead of the set.
     */
    private const SPINS = 'RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30';

    /**
     * **A start that moves takes every answer with it.** The table's first
     * row, and the plainest reading of the MUST above it.
     */
    public function testAStartThatMovesTakesTheAnswersWithIt(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **And so does an end**, although no recurrence set hears of it: the
     * first row asks nothing of the instances, only that the property changed.
     */
    public function testAnEndThatMovesDoesAsWell(): void
    {
        $was = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nDTEND:20261101T110000Z", self::ACCEPTED));
        $is = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nDTEND:20261101T120000Z", self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **A duration is the same row**, and reaches the same end by another
     * spelling — RFC 5545 §3.6.1 letting a `VEVENT` carry either.
     */
    public function testADurationIsTheSameRow(): void
    {
        $was = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nDURATION:PT1H", self::ACCEPTED));
        $is = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nDURATION:PT2H", self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **And a due date**, which only a `VTODO` has (RFC 5545 §3.6.2). The row
     * names four properties and this is the fourth.
     */
    public function testADueDateIsInThatRowToo(): void
    {
        $was = $this->anObject($this->aTask('DUE:20261101T100000Z'));
        $is = $this->anObject($this->aTask('DUE:20261102T100000Z'));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **Everything else leaves the answers standing.** A reschedule is what
     * the section defines it to be: those seven properties and no others. The
     * organizer who moves a meeting to another room has §3.2.8's own MAY for
     * re-inviting, and it is a MAY for the client to use, not this.
     */
    public function testAnotherPropertyLeavesTheAnswersStanding(): void
    {
        $was = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nLOCATION:Room 1", self::ACCEPTED));
        $is = $this->anObject($this->aMeeting("DTSTART:20261101T100000Z\r\nLOCATION:Room 2", self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **The organizer's own answer is theirs to keep.** "except the one that
     * corresponds to the 'Organizer'" — the attendee property that names the
     * same calendar user as the `ORGANIZER`.
     */
    public function testTheOrganizersOwnAnswerIsLeftWhereItStands(): void
    {
        $theirs = 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::BERNARD;
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED . "\r\n" . $theirs));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::ACCEPTED . "\r\n" . $theirs));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
        self::assertStringContainsString($theirs, self::text($is));
    }

    /**
     * **And so is their answer under another of their addresses.** §1.2 has
     * "'Organizer' refers to a role of a calendar user" and §2.4.1 gives a
     * calendar user as many addresses as the server knows of them, so the one
     * that "corresponds to the 'Organizer'" is the calendar user and not the
     * spelling the `ORGANIZER` property happens to carry. The same reading
     * {@see \DavServices\Plugin\Scheduling} makes of §3.2.1's MUST NOT, where
     * "every address of the owner's is the owner".
     */
    public function testAnotherOfTheOrganizersAddressesIsStillTheOrganizer(): void
    {
        $theirs = 'ATTENDEE;PARTSTAT=ACCEPTED:urn:uuid:bernard';
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED . "
" . $theirs));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::ACCEPTED . "
" . $theirs));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
        self::assertStringContainsString($theirs, self::text($is));
    }

    /**
     * **An attendee the server does not schedule for is nobody to un-invite.**
     * §3.2.8's first sentence states the whole section's condition, and §7.1
     * gives `CLIENT` its meaning: the organizer's client sends that invitation
     * itself, so the answer on it is not this server's to take back.
     */
    public function testAnAttendeeWhoseAgentIsTheClientKeepsTheirAnswer(): void
    {
        $theirs = 'ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:' . self::ERIC;
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED . "\r\n" . $theirs));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::ACCEPTED . "\r\n" . $theirs));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
        self::assertStringContainsString($theirs, self::text($is));
    }

    /**
     * **A reminder's attendees are not anybody's answer.** RFC 5545 §3.6.6
     * makes a `VALARM`'s attendees "the attendees… to be sent an email", the
     * same reading {@see \DavServices\CalDav\Schedule\Instances::of()} makes
     * wherever it takes an object apart.
     */
    public function testAnAlarmsAttendeeIsNoAnswerToTakeBack(): void
    {
        $alarm = "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT15M\r\nDESCRIPTION:x\r\nSUMMARY:x\r\n"
            . 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC . "\r\nEND:VALARM\r\n";
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED, $alarm));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::ACCEPTED, $alarm));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC, self::text($is));
    }

    /**
     * **A rule that reaches further adds instances, and that is the second
     * row.** `COUNT=3` to `COUNT=4` holds every identifier it held and one
     * more, so the set after holds one the set before did not.
     */
    public function testARuleThatAddsAnInstanceTakesTheAnswers(): void
    {
        $was = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));
        $is = $this->anObject($this->aSeries('RRULE:FREQ=DAILY;COUNT=4', self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **A rule that only reaches less is in none of the rows.** The three
     * conditional rows name an addition, a change in time and a
     * reinstatement; dropping instances is none of the three, and an attendee
     * who answered for the instances that remain answered for those.
     */
    public function testARuleThatOnlyDropsInstancesLeavesTheAnswersAlone(): void
    {
        $was = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));
        $is = $this->anObject($this->aSeries('RRULE:FREQ=DAILY;COUNT=2', self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **A date added to the series is the third row.** An `RDATE` is an
     * instance no rule generates (RFC 5545 §3.8.5.2), and it arrives as an
     * identifier the set before did not hold.
     */
    public function testADateAddedToTheSeriesTakesTheAnswers(): void
    {
        $was = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));
        $is = $this->anObject(
            $this->aSeries(self::DAILY . "\r\nRDATE:20261110T100000Z", self::ACCEPTED),
        );

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An exclusion taken away puts an instance back, and that is the fourth
     * row.** "results in the reinstatement of recurring instances" — the
     * identifier is in the set again, and nobody has answered for it since.
     */
    public function testAnExclusionTakenAwayTakesTheAnswers(): void
    {
        $was = $this->anObject(
            $this->aSeries(self::DAILY . "\r\nEXDATE:20261102T100000Z", self::ACCEPTED),
        );
        $is = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An exclusion added takes nothing back.** The fourth row names a
     * change or a removal "that results in the reinstatement of recurring
     * instances"; adding one reinstates nothing.
     */
    public function testAnExclusionAddedLeavesTheAnswersAlone(): void
    {
        $was = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));
        $is = $this->anObject(
            $this->aSeries(self::DAILY . "\r\nEXDATE:20261102T100000Z", self::ACCEPTED),
        );

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **On each affected component, and on no other.** The second row says
     * where the reset goes, and an overridden instance whose start moves is
     * the one component the change reached: the master answers for the other
     * instances exactly as it did.
     */
    public function testOnlyTheComponentThatWasAffectedIsReset(): void
    {
        $master = $this->aSeries(self::DAILY, 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC);
        $was = $this->anObject($master, $this->anInstance('20261102T100000Z', '20261102T100000Z'));
        $is = $this->anObject($master, $this->anInstance('20261102T100000Z', '20261102T140000Z'));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));

        $text = self::text($is);

        self::assertStringContainsString(self::RESET, $text);
        self::assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC, $text);
    }

    /**
     * **A range moves the instances after it, and they are affected too.** RFC
     * 5545 §3.2.13 gives `RANGE=THISANDFUTURE` "this and all subsequent
     * recurrence instances", and {@see \DavServices\VObject\Recur\ObjectSet}
     * carries that shift onto every identifier the series generates after it.
     * Those instances keep their identifiers and change their time, which is
     * the second row's other half — so the master is an affected component as
     * well as the override.
     */
    public function testARangeThatMovesTheRestResetsTheMasterToo(): void
    {
        $master = $this->aSeries(self::DAILY, self::ACCEPTED);
        $override = "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T140000Z\r\n"
            . "RECURRENCE-ID;RANGE=THISANDFUTURE:20261102T100000Z\r\n" . self::ORGANISED . "\r\n"
            . 'ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC . "\r\nEND:VEVENT\r\n";
        $was = $this->anObject($master);
        $is = $this->anObject($master, $override);

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **A series this library cannot finish expanding is treated as
     * affected.** `FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30` names a day that never
     * comes, as {@see \DavServices\CalDav\Limits} puts it, and there is no way
     * to learn what such a rule generates except by giving up on it.
     *
     * **So the doubt is resolved towards the answer being taken back**, and
     * §3.2.8's own last sentence is what makes that the safe side: a server
     * MAY set `PARTSTAT` to `NEEDS-ACTION` "at any other time", while leaving
     * it standing where an instance was in fact added would break a MUST. The
     * change to the rule is known to have happened; only its effect is not.
     */
    public function testARuleThatCannotBeExpandedIsTakenAsAffected(): void
    {
        $was = $this->anObject($this->aSeries(self::DAILY, self::ACCEPTED));
        $is = $this->anObject(
            $this->aSeries('RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', self::ACCEPTED),
        );

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **A series nobody touched is not expanded at all.** The three
     * conditional rows each begin with a change to the property they name, so
     * where `RRULE`, `RDATE` and `EXDATE` all stand as they stood there is
     * nothing for an expansion to answer — and the rule that cannot be
     * expanded, left alone, says so: it comes back false.
     */
    public function testARuleLeftAsItStandsIsNotAskedAboutAtAll(): void
    {
        $rule = 'RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30';
        $was = $this->anObject($this->aSeries($rule, self::ACCEPTED . "\r\nSUMMARY:One"));
        $is = $this->anObject($this->aSeries($rule, self::ACCEPTED . "\r\nSUMMARY:Two"));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **A creation reschedules nothing.** §3.2.1.2's `<Absent>` row: there is
     * no revision for an instance to be impacted against, and the answers an
     * organizer wrote on a new event are {@see
     * \DavServices\CalDav\Schedule\Modification::whoseAnswerItSets()}'s
     * business, not this one's.
     */
    public function testACreationReschedulesNothing(): void
    {
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo(null, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **An answer that is already `NEEDS-ACTION` is no change to make.** The
     * return value says whether the resource now differs from what the client
     * sent, and a reset that resets nothing must not have it written back —
     * {@see \DavServices\Plugin\Scheduling} writes the object again only for a
     * change it made.
     */
    public function testAnAnswerAlreadyNeedingActionIsNoChange(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', self::RESET));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', self::RESET));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An attendee without a `PARTSTAT` is left without one.** RFC 5545
     * §3.2.12 makes `NEEDS-ACTION` that parameter's default, so there is
     * nothing to reset — and writing the default out would be a change to the
     * resource that says nothing.
     */
    public function testAnAttendeeWithoutAnAnswerIsLeftWithoutOne(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', 'ATTENDEE:' . self::CYRUS));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', 'ATTENDEE:' . self::CYRUS));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString("ATTENDEE:" . self::CYRUS . "\r\n", self::text($is));
    }

    /**
     * **An override taken away gives its instance back to the master, and
     * that is a change in time.** The component is gone; the instance is not.
     * It stood at 14:00 because the override said so, and it stands at 10:00
     * again because the rule does — so an attendee who accepted the one is
     * expected at the other, which is the second row's "change in time for
     * existing recurring instances". The affected component is the master,
     * because that is what answers for the instance now.
     */
    public function testAnOverrideTakenAwayGivesItsInstanceBackAndTakesTheAnswers(): void
    {
        $master = $this->aSeries(self::DAILY, self::ACCEPTED);
        $was = $this->anObject($master, $this->anInstance('20261102T100000Z', '20261102T140000Z'));
        $is = $this->anObject($master);

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An instance the series no longer reaches takes nobody's answer.** Here
     * the identifier itself is gone from the set, and a dropped instance is in
     * none of the three conditional rows — not an addition, not a change in
     * time, not a reinstatement. An attendee who accepted the series accepted
     * the instances that remain of it.
     */
    public function testAnInstanceTheSeriesNoLongerReachesIsNoResetAtAll(): void
    {
        $was = $this->anObject(
            $this->aSeries(self::DAILY, self::ACCEPTED),
            $this->anInstance('20261103T100000Z', '20261103T140000Z'),
        );
        $is = $this->anObject($this->aSeries('RRULE:FREQ=DAILY;COUNT=2', self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **Every component a time changed in has its own answers taken back.**
     * §3.2.8's first row is read "on each affected component", and a series
     * whose master and whose overriding instance both last half an hour longer
     * has two of them. Each carries its own `ATTENDEE` properties — RFC 5545
     * §3.6.1 making an overriding component a component in its own right — so
     * the reset applied to one says nothing about the other.
     *
     * The recurrence set is untouched here, the same three instances starting
     * at the same three moments, which is what leaves the first row alone to
     * answer.
     */
    public function testEveryComponentATimeChangedInHasItsOwnAnswersTakenBack(): void
    {
        $was = $this->anObject(
            $this->aSeries(self::DAILY, "DTEND:20261101T110000Z\r\n" . self::ACCEPTED),
            $this->anInstance('20261102T100000Z', '20261102T140000Z', "DTEND:20261102T150000Z\r\n"),
        );
        $is = $this->anObject(
            $this->aSeries(self::DAILY, "DTEND:20261101T113000Z\r\n" . self::ACCEPTED),
            $this->anInstance('20261102T100000Z', '20261102T140000Z', "DTEND:20261102T153000Z\r\n"),
        );

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));

        $text = self::text($is);

        self::assertSame(2, substr_count($text, self::RESET));
        self::assertStringNotContainsString('PARTSTAT=ACCEPTED', $text);
    }

    /**
     * **A start that keeps its wall clock and changes its zone has moved.**
     * RFC 5545 §3.8.2.4 has a `DTSTART` mean one moment only together with its
     * `TZID`, so a value compared without its parameters would miss a meeting
     * moved from Berlin to London at ten o'clock.
     */
    public function testAStartThatOnlyChangesItsZoneHasMoved(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART;TZID=Europe/Berlin:20261101T100000', self::ACCEPTED));
        $is = $this->anObject($this->aMeeting('DTSTART;TZID=Europe/London:20261101T100000', self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **And two parameters written the other way round have not.** Nothing in
     * RFC 5545 §3.1's grammar makes the order of parameters mean anything —
     * the reading {@see \DavServices\VObject\Property::offsetSet()} already
     * keeps to — so a client that rewrites a property it did not change must
     * not cost its attendees their answers.
     */
    public function testTwoParametersInAnotherOrderAreNoMove(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART;X-A=1;X-B=2:20261101T100000Z', self::ACCEPTED));
        $is = $this->anObject($this->aMeeting('DTSTART;X-B=2;X-A=1:20261101T100000Z', self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **A parameter name respelled is no change, and its value respelled is.**
     * RFC 5545 §3.5 has "Property names, parameter names, and enumerated
     * parameter values are case-insensitive", while §3.1 makes a value
     * case-sensitive — and a `TZID` value is the name of a time zone, which
     * {@see \DavServices\VObject\TimeZone\Definition} matches as written.
     */
    public function testAParameterNameRespelledIsNoChangeAndItsValueIs(): void
    {
        $was = $this->anObject($this->aMeeting('DTSTART;TZID=Europe/Berlin:20261101T100000', self::ACCEPTED));
        $same = $this->anObject($this->aMeeting('DTSTART;tzid=Europe/Berlin:20261101T100000', self::ACCEPTED));
        $other = $this->anObject($this->aMeeting('DTSTART;TZID=europe/berlin:20261101T100000', self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $same, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($same));

        self::assertTrue(Reschedule::appliedTo($was, $other, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($other));
    }

    /**
     * **Every reason to pass an attendee over passes over that one only.**
     * Four attendees in one component, three of them left alone for three
     * different sentences — the organizer under another of their addresses
     * (§3.2.8's own exception), an attendee the client schedules for (§7.1),
     * and one who has not answered at all (RFC 5545 §3.2.12's default) — and
     * the fourth, who comes last, still has their answer taken back.
     */
    public function testEveryReasonToPassAnAttendeeOverPassesOverThatOneOnly(): void
    {
        $others = 'ATTENDEE;PARTSTAT=ACCEPTED:urn:uuid:bernard' . "\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:' . self::ERIC . "\r\n"
            . 'ATTENDEE:mailto:cyrus-the-second@example.com';
        $was = $this->anObject($this->aMeeting('DTSTART:20261101T100000Z', $others . "\r\n" . self::ACCEPTED));
        $is = $this->anObject($this->aMeeting('DTSTART:20261101T110000Z', $others . "\r\n" . self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));

        $text = self::text($is);

        self::assertStringContainsString(self::RESET, $text);
        self::assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED:urn:uuid:bernard', $text);
        self::assertStringContainsString('ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:' . self::ERIC, $text);
        self::assertStringContainsString('ATTENDEE:mailto:cyrus-the-second@example.com', $text);
    }

    /**
     * **Two exclusions written the other way round exclude the same
     * instances.** RFC 5545 §3.8.5.1 lets the exclusions of one series be
     * written as several `EXDATE` properties, and which of them comes first
     * says nothing about the recurrence set they describe. Asked of a series
     * that cannot be expanded, because that is where the properties are read
     * instead of the set.
     */
    public function testTwoExclusionsInAnotherOrderAreTheSameSeries(): void
    {
        $first = "EXDATE:20270202T100000Z\r\nEXDATE:20270203T100000Z";
        $second = "EXDATE:20270203T100000Z\r\nEXDATE:20270202T100000Z";
        $was = $this->anObject($this->aSeries(self::SPINS . "\r\n" . $first, self::ACCEPTED));
        $is = $this->anObject($this->aSeries(self::SPINS . "\r\n" . $second, self::ACCEPTED));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **And the second of them changing is a change all the same**, which is
     * what reading all of a name's properties is for.
     */
    public function testTheSecondOfTwoExclusionsChangingIsStillAChange(): void
    {
        $first = "EXDATE:20270202T100000Z\r\nEXDATE:20270203T100000Z";
        $second = "EXDATE:20270202T100000Z\r\nEXDATE:20270204T100000Z";
        $was = $this->anObject($this->aSeries(self::SPINS . "\r\n" . $first, self::ACCEPTED));
        $is = $this->anObject($this->aSeries(self::SPINS . "\r\n" . $second, self::ACCEPTED));

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An override arriving on a series that cannot be expanded takes the
     * answers.** The overriding component gives its instance a time of its
     * own, and where the set cannot say which instance that is, the doubt is
     * §3.2.8's own MAY to resolve.
     */
    public function testAnOverrideArrivingOnASeriesThatSpinsTakesTheAnswers(): void
    {
        $was = $this->anObject($this->aSeries(self::SPINS, self::ACCEPTED));
        $is = $this->anObject(
            $this->aSeries(self::SPINS, self::ACCEPTED),
            $this->anInstance('20270228T100000Z', '20270228T140000Z'),
        );

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **Two overrides written the other way round are the same series.** RFC
     * 5545 §3.6 imposes no order on the components of a calendar object, so
     * which of two overriding components is written first says nothing either.
     */
    public function testTwoOverridesInAnotherOrderAreTheSameSeries(): void
    {
        $first = $this->anInstance('20270228T100000Z', '20270228T140000Z');
        $second = $this->anInstance('20270301T100000Z', '20270301T140000Z');
        $was = $this->anObject($this->aSeries(self::SPINS, self::ACCEPTED), $first, $second);
        $is = $this->anObject($this->aSeries(self::SPINS, self::ACCEPTED), $second, $first);

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * **A series without a `UID` cannot be expanded either, and is read the
     * same way.** RFC 5545 §3.6.1 makes `UID` REQUIRED in a `VEVENT` and
     * {@see \DavServices\CalDav\CalendarObject::in()} does not ask for it —
     * `CALDAV:valid-calendar-data` is what refuses such a body, and what this
     * does with one in the meantime is written down rather than left to
     * chance.
     */
    public function testASeriesWithoutAUidIsReadByItsPropertiesAlone(): void
    {
        $was = $this->anObject(self::withoutUid($this->aSeries(self::DAILY, self::ACCEPTED)));
        $is = $this->anObject(
            self::withoutUid($this->aSeries('RRULE:FREQ=DAILY;COUNT=4', self::ACCEPTED)),
        );

        self::assertTrue(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::RESET, self::text($is));
    }

    /**
     * **An object of overrides alone with no start is no series to compare.**
     * {@see \DavServices\VObject\Recur\ObjectSet::of()} reads an overriding
     * component through its `DTSTART` — RFC 5545 §3.6.1 making that property
     * what says when an instance is — so an object without one cannot be
     * expanded, and there is no master for the doubt to be resolved on.
     */
    public function testAnObjectOfOverridesWithoutAStartIsNoSeries(): void
    {
        $override = "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n"
            . 'RECURRENCE-ID:20261102T100000Z' . "\r\n" . self::ORGANISED . "\r\n"
            . self::ACCEPTED . "\r\nEND:VEVENT\r\n";
        $was = $this->anObject($override);
        $is = $this->anObject(str_replace('20261102T100000Z', '20261103T100000Z', $override));

        self::assertFalse(Reschedule::appliedTo($was, $is, self::OWNERS));
        self::assertStringContainsString(self::ACCEPTED, self::text($is));
    }

    /**
     * A `VCALENDAR` holding those components.
     */
    private function anObject(string ...$components): Component
    {
        return CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . implode('', $components)
            . "END:VCALENDAR\r\n",
        ) ?? self::fail('The fixture is no calendar object.');
    }

    /**
     * A meeting that is no series, carrying those lines and those components.
     */
    private function aMeeting(string $times, string $properties, string $inner = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n" . $times . "\r\n"
            . self::ORGANISED . "\r\n" . $properties . "\r\n" . $inner . "END:VEVENT\r\n";
    }

    /**
     * A task carrying that line, for the fourth property of the first row.
     */
    private function aTask(string $due): string
    {
        return "BEGIN:VTODO\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n" . $due . "\r\n"
            . self::ORGANISED . "\r\n" . self::ACCEPTED . "\r\nEND:VTODO\r\n";
    }

    /**
     * The master of a series, carrying that recurrence rule and those
     * properties.
     */
    private function aSeries(string $recurrence, string $properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $recurrence . "\r\n" . self::ORGANISED . "\r\n" . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * A component overriding one instance, at that time, carrying those
     * further lines.
     */
    private function anInstance(string $id, string $start, string $more = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:" . $start . "\r\n"
            . 'RECURRENCE-ID:' . $id . "\r\n" . self::ORGANISED . "\r\n"
            . self::ACCEPTED . "\r\n" . $more . "END:VEVENT\r\n";
    }

    /**
     * That component with its `UID` left out.
     */
    private static function withoutUid(string $component): string
    {
        return str_replace("UID:1\r\n", '', $component);
    }

    /**
     * What that object says now, with the folding taken out.
     *
     * RFC 5545 §3.1 breaks a line longer than 75 octets, and an attendee with
     * two parameters passes that — so an expectation written as one line would
     * be looking for something the file never contains.
     */
    private static function text(Component $object): string
    {
        return str_replace("\r\n ", '', (new Serializer())->write($object));
    }
}
