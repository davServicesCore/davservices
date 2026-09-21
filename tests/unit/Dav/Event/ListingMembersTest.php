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

namespace DavServices\Tests\Unit\Dav\Event;

use DavServices\Dav\Event\ListingMembers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §8.1.1 and R-ACL-06.
 *
 * **Hiding a resource is only half done by refusing it.** A `404` on a member
 * nobody may read says nothing while the listing of its parent still names
 * it: the client has been told the thing exists, which is what hiding it was
 * meant to prevent. So the listing is asked first.
 *
 * **Every member is offered at once**, because deciding this is a question to
 * whatever knows the rules, and a collection of two hundred members would
 * otherwise be two hundred questions (R-PRIV-01).
 *
 * Nobody listening conceals nothing, which is what a server without access
 * control does: it has no reason to hide anything.
 */
#[CoversClass(ListingMembers::class)]
final class ListingMembersTest extends TestCase
{
    public function testKnowsWhichCollectionIsBeingListed(): void
    {
        self::assertSame('calendars', $this->event()->path());
    }

    public function testKnowsEveryMemberThatWasFound(): void
    {
        self::assertSame(
            ['calendars/work', 'calendars/private', 'calendars/holidays'],
            $this->event()->members(),
        );
    }

    /**
     * With nobody listening everything appears, which is a server without
     * access control (R-ARC-02).
     */
    public function testEverythingAppearsUntilSomebodyHidesIt(): void
    {
        self::assertSame($this->event()->members(), $this->event()->visible());
    }

    public function testKeepsConcealedMembersOutOfTheAnswer(): void
    {
        $event = $this->event();

        $event->conceal('calendars/private');

        self::assertSame(['calendars/work', 'calendars/holidays'], $event->visible());
    }

    public function testConcealsSeveralAtOnce(): void
    {
        $event = $this->event();

        $event->conceal('calendars/private', 'calendars/holidays');

        self::assertSame(['calendars/work'], $event->visible());
    }

    /**
     * **The order is the order they were found in.** A client reading a
     * listing is reading a collection, and a server that shuffled it on the
     * way out would be answering a different question each time.
     */
    public function testWhatIsLeftKeepsTheOrderItWasFoundIn(): void
    {
        $event = $this->event();

        $event->conceal('calendars/work');

        self::assertSame(['calendars/private', 'calendars/holidays'], $event->visible());
    }

    /**
     * Two listeners may hide the same member, and one that is already hidden
     * stays hidden once: a listener may conceal what it likes without having
     * to know what another has already done.
     */
    public function testConcealingTheSameMemberTwiceIsConcealingItOnce(): void
    {
        $event = $this->event();

        $event->conceal('calendars/private');
        $event->conceal('calendars/private');

        self::assertSame(['calendars/work', 'calendars/holidays'], $event->visible());
    }

    /**
     * And a path that is no member of this listing is simply not in it —
     * a listener that works from its own list of secrets need not check
     * first.
     */
    public function testConcealingSomethingThatIsNotThereChangesNothing(): void
    {
        $event = $this->event();

        $event->conceal('somewhere/else');

        self::assertSame($event->members(), $event->visible());
    }

    private function event(): ListingMembers
    {
        return new ListingMembers('calendars', ['calendars/work', 'calendars/private', 'calendars/holidays']);
    }
}
