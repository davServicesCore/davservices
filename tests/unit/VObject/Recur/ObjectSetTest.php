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

namespace DavServices\Tests\Unit\VObject\Recur;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\Parameter;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Recur\ExpandedSet;
use DavServices\VObject\Recur\Instance;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\Zone;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for overridden instances. RFC 5545 §3.8.4.4 and §3.2.13, RFC 4791
 * §4.1. R-RRULE-03.
 *
 * **`ExpandedSet` answers when a component recurs; this answers what the
 * instances of an object are.** RFC 4791 §4.1 gives the two words for it:
 * the "**master**" recurring component, which "defines the recurrence 'set'
 * and does not contain any RECURRENCE-ID property", and the "**overridden**"
 * instances, which "modify the behavior of a regular instance, and thus
 * include a RECURRENCE-ID property". They share a `UID` and live in one
 * resource.
 *
 * ## A recurrence identifier points at where an instance was
 *
 * §3.8.4.4: "The property value is the **original** value of the 'DTSTART'
 * property of the recurrence instance", and the memo says it twice because it
 * is the thing to get wrong: "The DATE-TIME value is set to the time when the
 * original recurrence instance would occur; meaning that **if the intent is to
 * change a Friday meeting to Thursday, the DATE-TIME is still set to the
 * original Friday meeting**."
 *
 * So an instance has two dates — where the series put it and where it is — and
 * {@see Instance} carries both.
 *
 * ## `RANGE` is a shift, not a rewrite
 *
 * §3.2.13: "If this parameter is not specified on an allowed property, then
 * the **default range is the single instance** specified by the recurrence
 * identifier value of the property." And where it is specified, §3.8.4.4 says
 * exactly what it does:
 *
 * > When the given recurrence instance is rescheduled, all subsequent
 * > instances are also rescheduled **by the same time difference**. For
 * > instance, if the given recurrence instance is rescheduled to start 2
 * > hours later, then all subsequent instances are also rescheduled 2 hours
 * > later.
 *
 * With two sentences that keep it from running away:
 *
 * > **Subsequent instances are determined by their "RECURRENCE-ID" value and
 * > not their current scheduled start time.** … **Subsequent instances defined
 * > in separate components are not impacted** by the given recurrence
 * > instance.
 *
 * The first is why the set is walked in recurrence-identifier order; the
 * second is why an instance with a component of its own keeps its own start.
 *
 * ## What the memo leaves open, and what is read into it
 *
 * **An override whose identifier names no instance of the master.** RFC 4791
 * §4.1 says an object may "just contain components that represent 'overridden'
 * instances … without also including the 'master' recurring component", so an
 * override is an instance in its own right. Read the same way where a master
 * is present but does not generate that moment: the instance is in the set.
 *
 * **And the order of the starts.** A shifted instance can overtake its
 * neighbour, and nothing in the memo says the set is then sorted again — nor
 * could it be, a series that repeats for ever not fitting in a sort. So the
 * order is the recurrence identifiers', which is the order the memo itself
 * reasons in.
 */
#[CoversClass(ObjectSet::class)]
#[CoversClass(Instance::class)]
// Driven by this test as well; ci.yml says why naming them matters.
#[CoversClass(ExpandedSet::class)]
#[CoversClass(Iterator::class)]
final class ObjectSetTest extends TestCase
{
    /**
     * **RFC 4791 §4.1's own example**, which it gives to show what belongs in
     * one resource: a weekly meeting with one instance moved an hour later.
     */
    public function testTheExampleOfTheSpecification(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041213T130000Z',
                '20041220T120000Z',
                '20041227T120000Z',
            ],
            self::expand([
                [
                    ['UID', '2@example.com'],
                    ['SUMMARY', 'Weekly Meeting'],
                    ['DTSTART', '20041206T120000Z'],
                    ['DTEND', '20041206T130000Z'],
                    ['RRULE', 'FREQ=WEEKLY'],
                ],
                [
                    ['UID', '2@example.com'],
                    ['SUMMARY', 'Weekly Meeting'],
                    ['RECURRENCE-ID', '20041213T120000Z'],
                    ['DTSTART', '20041213T130000Z'],
                    ['DTEND', '20041213T140000Z'],
                ],
            ], 4),
        );
    }

    /**
     * **The identifier still names the Friday.** "if the intent is to change
     * a Friday meeting to Thursday, the DATE-TIME is still set to the
     * original Friday meeting" — so the instance keeps the identifier it had
     * and reports a start a day earlier.
     */
    public function testAnIdentifierNamesWhereTheInstanceWas(): void
    {
        self::assertSame(
            ['20041203T120000Z', '20041210T120000Z -> 20041209T120000Z'],
            self::expand([
                [
                    ['UID', 'friday@example.com'],
                    ['DTSTART', '20041203T120000Z'],
                    ['RRULE', 'FREQ=WEEKLY;COUNT=2'],
                ],
                [
                    ['UID', 'friday@example.com'],
                    ['RECURRENCE-ID', '20041210T120000Z'],
                    ['DTSTART', '20041209T120000Z'],
                ],
            ]),
        );
    }

    /**
     * **"the default range is the single instance"** (§3.2.13). Without the
     * parameter, the instance after the overridden one is back on the
     * series' own schedule.
     */
    public function testWithoutARangeOneInstanceChanges(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041213T140000Z',
                '20041220T120000Z',
                '20041227T120000Z',
            ],
            self::expand([
                self::weekly(),
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z'],
                    ['DTSTART', '20041213T140000Z'],
                ],
            ]),
        );
    }

    /**
     * **"all subsequent instances are also rescheduled by the same time
     * difference. For instance, if the given recurrence instance is
     * rescheduled to start 2 hours later, then all subsequent instances are
     * also rescheduled 2 hours later."** The memo's own two hours.
     */
    public function testARangeShiftsEveryInstanceAfterItByTheSameDifference(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041213T140000Z',
                '20041220T120000Z -> 20041220T140000Z',
                '20041227T120000Z -> 20041227T140000Z',
            ],
            self::expand([
                self::weekly(),
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z', 'THISANDFUTURE'],
                    ['DTSTART', '20041213T140000Z'],
                ],
            ]),
        );
    }

    /**
     * **Two sentences at once.** "Subsequent instances defined in separate
     * components are not impacted by the given recurrence instance" — the
     * third instance keeps its own start rather than the shift's. And
     * "Subsequent instances are determined by their 'RECURRENCE-ID' value and
     * not their current scheduled start time" — which is how that component
     * is found at all, its identifier naming noon where the shift would have
     * put the instance at two.
     */
    public function testAnInstanceWithItsOwnComponentIsNotShifted(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041213T140000Z',
                '20041220T120000Z -> 20041220T090000Z',
                '20041227T120000Z -> 20041227T140000Z',
            ],
            self::expand([
                self::weekly(),
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z', 'THISANDFUTURE'],
                    ['DTSTART', '20041213T140000Z'],
                ],
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041220T120000Z'],
                    ['DTSTART', '20041220T090000Z'],
                ],
            ]),
        );
    }

    /**
     * **A later range takes over from an earlier one**, its own effective
     * range beginning where it does.
     */
    public function testALaterRangeTakesOverFromAnEarlierOne(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041213T140000Z',
                '20041220T120000Z -> 20041220T170000Z',
                '20041227T120000Z -> 20041227T170000Z',
            ],
            self::expand([
                self::weekly(),
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z', 'THISANDFUTURE'],
                    ['DTSTART', '20041213T140000Z'],
                ],
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041220T120000Z', 'THISANDFUTURE'],
                    ['DTSTART', '20041220T170000Z'],
                ],
            ]),
        );
    }

    /**
     * **An object may hold nothing but overrides.** RFC 4791 §4.1: "It is
     * possible for a calendar object resource to just contain components that
     * represent 'overridden' instances … without also including the 'master'
     * recurring component."
     */
    public function testAnObjectOfNothingButOverrides(): void
    {
        self::assertSame(
            ['20041206T120000Z', '20041213T120000Z -> 20041213T140000Z'],
            self::expand([
                [
                    ['UID', 'orphans@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z'],
                    ['DTSTART', '20041213T140000Z'],
                ],
                [
                    ['UID', 'orphans@example.com'],
                    ['RECURRENCE-ID', '20041206T120000Z'],
                    ['DTSTART', '20041206T120000Z'],
                ],
            ]),
        );
    }

    /**
     * **And an override the master does not generate is an instance too**,
     * read the same way: if a whole resource of them stands on its own, one
     * of them does. It takes its place by its identifier.
     */
    public function testAnOverrideTheSeriesDoesNotGenerateIsStillAnInstance(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041209T120000Z -> 20041209T150000Z',
                '20041213T120000Z',
            ],
            self::expand([
                [
                    ['UID', 'weekly@example.com'],
                    ['DTSTART', '20041206T120000Z'],
                    ['RRULE', 'FREQ=WEEKLY;COUNT=2'],
                ],
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041209T120000Z'],
                    ['DTSTART', '20041209T150000Z'],
                ],
            ]),
        );
    }

    /**
     * **The identifiers set the order, even where a start overtakes its
     * neighbour.** "Subsequent instances are determined by their
     * 'RECURRENCE-ID' value and not their current scheduled start time", and
     * nothing in the memo sorts the set again afterwards — nor could anything,
     * a series that repeats for ever not fitting in a sort.
     */
    public function testTheIdentifiersSetTheOrderEvenWhenAStartOvertakes(): void
    {
        self::assertSame(
            [
                '20041206T120000Z',
                '20041213T120000Z -> 20041225T120000Z',
                '20041220T120000Z',
            ],
            self::expand([
                [
                    ['UID', 'weekly@example.com'],
                    ['DTSTART', '20041206T120000Z'],
                    ['RRULE', 'FREQ=WEEKLY;COUNT=3'],
                ],
                [
                    ['UID', 'weekly@example.com'],
                    ['RECURRENCE-ID', '20041213T120000Z'],
                    ['DTSTART', '20041225T120000Z'],
                ],
            ]),
        );
    }

    /**
     * **The overriding component is what describes its instance.** An
     * override may change anything the series set — a summary as readily as a
     * start.
     */
    public function testTheOverridingComponentDescribesItsInstance(): void
    {
        $instances = [];

        foreach (self::setOf([
            self::weekly(),
            [
                ['UID', 'weekly@example.com'],
                ['SUMMARY', 'Moved and renamed'],
                ['RECURRENCE-ID', '20041213T120000Z'],
                ['DTSTART', '20041213T140000Z'],
            ],
        ])->instances() as $instance) {
            $instances[] = $instance->component()->property('SUMMARY')?->value();

            if (count($instances) === 3) {
                break;
            }
        }

        self::assertSame(['Weekly Meeting', 'Moved and renamed', 'Weekly Meeting'], $instances);
    }

    /**
     * **`THISANDPRIOR` is no longer a range.** §3.2.13's grammar admits one
     * value — `rangeparam = "RANGE" "=" "THISANDFUTURE"` — and says of the
     * other that it "is deprecated by this revision of iCalendar and MUST NOT
     * be generated by applications"; §A.3 that it "can no longer be used with
     * the 'RANGE' parameter".
     */
    public function testThePriorRangeIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('THISANDFUTURE');

        self::expand([
            self::weekly(),
            [
                ['UID', 'weekly@example.com'],
                ['RECURRENCE-ID', '20041213T120000Z', 'THISANDPRIOR'],
                ['DTSTART', '20041213T140000Z'],
            ],
        ]);
    }

    /**
     * **"This property MUST have the same value type as the 'DTSTART'
     * property contained within the recurring component."** A day cannot
     * identify an instance of a series counted in moments.
     */
    public function testAnIdentifierOfAnotherValueTypeIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('value type');

        self::expand([
            self::weekly(),
            [
                ['UID', 'weekly@example.com'],
                ['RECURRENCE-ID', '20041213', null, 'DATE'],
                ['DTSTART', '20041213', null, 'DATE'],
            ],
        ]);
    }

    /**
     * **A series of days is shifted in days.** The same difference, whatever
     * the value type: an all-day instance moved to the next day moves every
     * instance after it to the next day.
     */
    public function testASeriesOfDaysIsShiftedTheSameWay(): void
    {
        self::assertSame(
            ['20041206', '20041213 -> 20041214', '20041220 -> 20041221'],
            self::expand([
                [
                    ['UID', 'days@example.com'],
                    ['DTSTART', '20041206', null, 'DATE'],
                    ['RRULE', 'FREQ=WEEKLY;COUNT=3'],
                ],
                [
                    ['UID', 'days@example.com'],
                    ['RECURRENCE-ID', '20041213', 'THISANDFUTURE', 'DATE'],
                    ['DTSTART', '20041214', null, 'DATE'],
                ],
            ], 3),
        );
    }

    /**
     * **A series in local time stays in local time.** §3.3.5 gives three
     * forms of a `date-time`, and a shifted instance is written back in the
     * one its identifier had — a floating series does not acquire a `Z`
     * because an override moved it.
     */
    public function testASeriesInLocalTimeIsShiftedInLocalTime(): void
    {
        self::assertSame(
            ['20041206T120000', '20041213T120000 -> 20041213T140000', '20041220T120000 -> 20041220T140000'],
            self::expand([
                [
                    ['UID', 'floating@example.com'],
                    ['DTSTART', '20041206T120000'],
                    ['RRULE', 'FREQ=WEEKLY;COUNT=3'],
                ],
                [
                    ['UID', 'floating@example.com'],
                    ['RECURRENCE-ID', '20041213T120000', 'THISANDFUTURE'],
                    ['DTSTART', '20041213T140000'],
                ],
            ], 3),
        );
    }

    /**
     * **An overriding component says where its instance begins.** §3.8.4.4
     * identifies an instance by where it **was**; where it is now can only
     * come from the component's own `DTSTART`, and §3.6.1 requires one of an
     * event anyway.
     */
    public function testAnOverrideWithoutAStartIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DTSTART');

        self::expand([
            self::weekly(),
            [
                ['UID', 'weekly@example.com'],
                ['RECURRENCE-ID', '20041213T120000Z'],
                ['SUMMARY', 'Nowhere'],
            ],
        ]);
    }

    /**
     * **A component without a `UID` is none of this series' business.** RFC
     * 4791 §4.1 allows exactly one such component beside the events: a
     * resource "MUST NOT contain more than one type of calendar component …
     * with the exception of VTIMEZONE components, which MUST be specified for
     * each unique TZID parameter value". It stands before the master here, so
     * that passing over it does not mean stopping at it.
     */
    public function testAComponentWithoutAUidIsPassedOver(): void
    {
        $calendar = new Component('VCALENDAR');
        $zone = new Component('VTIMEZONE');
        $zone->add(new Property('TZID', 'Europe/Berlin'));
        $calendar->add($zone);

        $event = new Component('VEVENT');

        foreach (self::weekly() as $property) {
            $event->add(new Property($property[0], $property[1]));
        }

        $calendar->add($event);

        $instances = [];

        foreach (ObjectSet::of($calendar, 'weekly@example.com')->instances() as $instance) {
            $instances[] = $instance->start()->encode();

            if (count($instances) === 2) {
                break;
            }
        }

        self::assertSame(['20041206T120000Z', '20041213T120000Z'], $instances);
    }

    /**
     * **And the form of the time has to match as well.** §3.8.4.4 asks for it
     * in the same breath as the value type: "Furthermore, this property MUST
     * be specified as a date with local time **if and only if** the 'DTSTART'
     * property contained within the recurring component is specified as a
     * date with local time."
     *
     * (The third form, a date with a time zone reference, is a `TZID`
     * parameter and waits for P4-11 with everything else that needs the zone.)
     */
    public function testAnIdentifierInAnotherFormOfTimeIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('local time');

        self::expand([
            self::weekly(),
            [
                ['UID', 'weekly@example.com'],
                ['RECURRENCE-ID', '20041213T120000'],
                ['DTSTART', '20041213T140000'],
            ],
        ]);
    }

    /**
     * **Two components without an identifier are two masters**, and which of
     * them defines the series is nothing the memo answers — RFC 4791 §4.1
     * knows one "master recurring component" per resource.
     */
    public function testTwoMastersAreRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('one master');

        self::expand([self::weekly(), self::weekly()]);
    }

    /**
     * **A master without a `DTSTART` defines no series.** §3.8.5.1 computes
     * the set "by considering the initial 'DTSTART' property", and §3.6.1
     * requires one of an event in any case — so it is refused here rather
     * than half-way through an expansion.
     */
    public function testAMasterWithoutAStartIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('master component has none');

        self::expand([
            [
                ['UID', 'weekly@example.com'],
                ['RRULE', 'FREQ=WEEKLY'],
            ],
            [
                ['UID', 'weekly@example.com'],
                ['RECURRENCE-ID', '20041213T120000Z'],
                ['DTSTART', '20041213T140000Z'],
            ],
        ]);
    }

    /**
     * And a `UID` the object does not carry has no set at all.
     */
    public function testAUidTheObjectDoesNotCarryIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('weekly@example.com');

        self::expand(
            [[['UID', 'other@example.com'], ['DTSTART', '20041206T120000Z']]],
            4,
            'weekly@example.com',
        );
    }

    /**
     * The weekly master RFC 4791 §4.1 builds its example on.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function weekly(): array
    {
        return [
            ['UID', 'weekly@example.com'],
            ['SUMMARY', 'Weekly Meeting'],
            ['DTSTART', '20041206T120000Z'],
            ['DTEND', '20041206T130000Z'],
            ['RRULE', 'FREQ=WEEKLY'],
        ];
    }

    /**
     * **A zone reaches the series an object carries**, so §3.3.10's instance
     * with no local time is passed over here as it is anywhere: "Such
     * recurrence instances MUST be ignored and MUST NOT be counted as part of
     * the recurrence set." The object-level set is what a server asks, so the
     * rule has to hold at this level too.
     */
    public function testAZoneReachesTheSeriesTheObjectCarries(): void
    {
        $instances = [];

        foreach (self::setOf([[
            ['UID', 'across@example.com'],
            ['DTSTART', '20240330T023000'],
            ['RRULE', 'FREQ=DAILY;COUNT=3'],
        ]], null, self::withoutTheMissingHour())->instances() as $instance) {
            $instances[] = $instance->recurrenceId()->encode();
        }

        self::assertSame(['20240330T023000', '20240401T023000', '20240402T023000'], $instances);
    }

    /**
     * A zone for which one single wall clock does not exist.
     */
    private static function withoutTheMissingHour(): Zone
    {
        return new class () implements Zone {
            public function momentOf(DateTime $local): ?DateTimeImmutable
            {
                if ($local->encode() === '20240331T023000') {
                    return null;
                }

                return new DateTimeImmutable($local->encode(), new DateTimeZone('UTC'));
            }
        };
    }

    /**
     * The instances of an object built from the components given, each as its
     * identifier and, where the two differ, the start it actually has.
     *
     * @param list<list<array{0: string, 1: string, 2?: string|null, 3?: string|null}>> $components
     *
     * @return list<string>
     */
    private static function expand(array $components, int $take = 4, ?string $uid = null): array
    {
        $instances = [];

        foreach (self::setOf($components, $uid)->instances() as $instance) {
            $identifier = $instance->recurrenceId()->encode();
            $start = $instance->start()->encode();

            $instances[] = $identifier === $start ? $identifier : $identifier . ' -> ' . $start;

            if (count($instances) === $take) {
                break;
            }
        }

        return $instances;
    }

    /**
     * @param list<list<array{0: string, 1: string, 2?: string|null, 3?: string|null}>> $components
     * @param string|null $uid The series to ask for, or null for the one the
     *                         first component carries
     */
    private static function setOf(array $components, ?string $uid = null, ?Zone $zone = null): ObjectSet
    {
        $calendar = new Component('VCALENDAR');

        foreach ($components as $properties) {
            $event = new Component('VEVENT');

            foreach ($properties as $property) {
                $added = new Property($property[0], $property[1]);
                $range = $property[2] ?? null;
                $type = $property[3] ?? null;

                if ($range !== null) {
                    $added->add(new Parameter('RANGE', [$range]));
                }

                if ($type !== null) {
                    $added->add(new Parameter('VALUE', [$type]));
                }

                $event->add($added);
            }

            $calendar->add($event);
        }

        // Without one named, the UID the first component carries: every
        // test here builds a single series.
        return ObjectSet::of(
            $calendar,
            $uid ?? $calendar->component('VEVENT')?->property('UID')?->value() ?? '',
            Iterator::ITERATIONS,
            $zone,
        );
    }
}
