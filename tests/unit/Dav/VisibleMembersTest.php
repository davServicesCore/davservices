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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\INode;
use DavServices\Dav\VisibleMembers;
use DavServices\Event\EventEmitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-ACL-06 and R-PRIV-01.
 *
 * **Refusing to read a resource is only half of hiding it.** A listing that
 * still named the thing has told the client it exists, which is most of what
 * somebody wanted to know. So every walk over a collection goes through here,
 * and there is one place that decides what appears.
 *
 * It was private inside `PROPFIND` until `DAV:principal-property-search`
 * needed the same walk. A search that listed members for itself would be a
 * second way into a collection — and the second way would be the one without
 * the access control.
 *
 * **The whole listing is offered at once** (R-PRIV-01). Deciding this is a
 * question to whatever knows the rules, and a collection of two hundred
 * members asked one at a time is two hundred questions.
 */
#[CoversClass(VisibleMembers::class)]
final class VisibleMembersTest extends TestCase
{
    public function testNamesEveryMemberByItsPath(): void
    {
        $members = VisibleMembers::of(new EventEmitter(), 'calendars', $this->calendars());

        self::assertSame(
            ['calendars/work.ics', 'calendars/home.ics', 'calendars/archive'],
            array_keys($members),
        );
    }

    /**
     * And each path leads to the node it names, because the caller is about
     * to ask that node for its properties.
     */
    public function testEachPathLeadsToItsOwnNode(): void
    {
        $members = VisibleMembers::of(new EventEmitter(), 'calendars', $this->calendars());

        self::assertSame(
            [
                'calendars/work.ics' => 'work.ics',
                'calendars/home.ics' => 'home.ics',
                'calendars/archive' => 'archive',
            ],
            array_map(static fn (INode $node): string => $node->name(), $members),
        );
    }

    /**
     * **R-ACL-06: what may not be read is not named either.** This is the
     * whole reason the walk is not simply `children()`.
     */
    public function testAConcealedMemberIsGone(): void
    {
        $events = new EventEmitter();

        $events->on(
            ListingMembers::class,
            static fn (ListingMembers $event) => $event->conceal('calendars/home.ics'),
        );

        $members = VisibleMembers::of($events, 'calendars', $this->calendars());

        self::assertSame(['calendars/work.ics', 'calendars/archive'], array_keys($members));
    }

    /**
     * **A listener conceals members; it does not invent them.** Whatever it
     * names that was never in the collection is not in the answer — there is
     * no node behind such a path, and a caller handed one would be handed the
     * wrong node under the right-looking name.
     */
    public function testAPathThatWasNeverAMemberDoesNotAppear(): void
    {
        $events = new EventEmitter();

        $events->on(
            ListingMembers::class,
            static fn (ListingMembers $event) => $event->conceal('calendars/invented.ics'),
        );

        $members = VisibleMembers::of($events, 'calendars', $this->calendars());

        self::assertSame(
            ['calendars/work.ics', 'calendars/home.ics', 'calendars/archive'],
            array_keys($members),
            'concealing what is not there changes nothing',
        );
    }

    /**
     * **R-PRIV-01: one question for the whole collection.** A listing decided
     * member by member turns one request into as many questions as there are
     * members, and the resolver contract counts exactly this.
     */
    public function testAsksAboutTheWholeListingAtOnce(): void
    {
        $events = new EventEmitter();
        $asked = 0;

        $events->on(ListingMembers::class, static function (ListingMembers $event) use (&$asked): void {
            ++$asked;

            self::assertCount(3, $event->members(), 'all of them, in one go');
        });

        VisibleMembers::of($events, 'calendars', $this->calendars());

        self::assertSame(1, $asked);
    }

    /**
     * An empty collection is still offered, because "there is nothing here"
     * is an answer a listener may want to see — and because a walk that
     * skipped the question for empty collections would have two behaviours
     * where one will do.
     */
    public function testAnEmptyCollectionIsStillOffered(): void
    {
        $events = new EventEmitter();
        $asked = 0;

        $events->on(ListingMembers::class, static function () use (&$asked): void {
            ++$asked;
        });

        self::assertSame([], VisibleMembers::of($events, 'empty', new MemoryCollection('empty')));
        self::assertSame(1, $asked);
    }

    /**
     * The root has no name of its own, so its members are named by theirs
     * alone — a member of the root is `calendars`, not `/calendars`.
     */
    public function testMembersOfTheRootAreNamedByThemselves(): void
    {
        $root = new MemoryCollection('');

        $root->add(new MemoryCollection('calendars'));

        self::assertSame(['calendars'], array_keys(VisibleMembers::of(new EventEmitter(), '', $root)));
    }

    private function calendars(): MemoryCollection
    {
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('home.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryCollection('archive'));

        return $calendars;
    }
}
