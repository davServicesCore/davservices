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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\ISubscriptionBackend;
use DavServices\Dav\Calendars\SubscriptionInfo;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every subscription store has to do, whatever it keeps them in.
 * R-CAL-04, R-BE-04.
 *
 * Written once and run against each implementation, because a backend that is
 * exchangeable is only exchangeable if they all behave the same.
 *
 * ## Why this store writes where the calendar store does not
 *
 * {@see \DavServices\Backend\ICalendarBackend} reads calendars and writes
 * their *objects*: a calendar's contents are the data, so making the calendar
 * is the collection's business ({@see \DavServices\Dav\IExtendedCollection}).
 *
 * **A subscription has no contents of its own.** It is a name, a feed and a
 * display name — the record *is* the datum, and its objects, if a server
 * caches any, are a copy of somebody else's. So the store that keeps a
 * subscription is also the one that makes and unmakes it, which is the shape
 * sabre/dav's own subscription support has for the same reason.
 *
 * ## The rules that run through all of it
 *
 * The same ones as for every other store here, because they are rules about
 * stores rather than about subscriptions:
 *
 * - **A missing thing is `null` or an empty list.** A principal with no
 *   subscriptions is an ordinary state — most people have none — and a store
 *   that threw would make "none yet" indistinguishable from "something is
 *   broken".
 * - **A missing thing that was named is `NotFound`.** Removing or changing one
 *   that is not there is a mistake the caller has to hear about, where
 *   answering nothing would let a client believe it had unsubscribed.
 * - **A member name is one path segment.** A store refuses anything that could
 *   reach outside the home it is writing to, with `Forbidden`.
 * - **A subscription is named inside a principal's home, not globally.** Two
 *   people both subscribing to the public holidays and both calling it
 *   `holidays` is the ordinary case rather than the exotic one.
 * - **No order is promised.** A store answers in whatever order its index has.
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
abstract class SubscriptionBackendContract extends TestCase
{
    protected const FEED = 'webcal://example.com/holidays.ics';

    /**
     * A store with one subscription, `holidays`, pointing at {@see self::FEED}.
     */
    abstract protected function backend(): ISubscriptionBackend;

    /**
     * A second principal with a subscription of the same name.
     *
     * **Abstract rather than optional.** Serving two people who both called a
     * subscription `holidays` is a promise of the interface, not a feature a
     * store may decline.
     */
    abstract protected function secondPrincipal(): string;

    /**
     * The principal whose subscriptions the store was set up with.
     */
    protected function principal(): string
    {
        return 'bernard';
    }

    public function testListsTheSubscriptionsOfAPrincipal(): void
    {
        $names = array_map(
            static fn (SubscriptionInfo $subscription): string => $subscription->name(),
            $this->backend()->subscriptions($this->principal()),
        );

        // No order is promised, so the case asks what is there rather than
        // where.
        self::assertContains('holidays', $names);
    }

    /**
     * **A principal with none has none, and that is not an error.** Most
     * people subscribe to nothing, and a store that refused would make the
     * ordinary state look like a broken one.
     */
    public function testAPrincipalWithNoSubscriptionsHasNone(): void
    {
        self::assertSame([], $this->backend()->subscriptions('nobody'));
    }

    public function testFindsOneByName(): void
    {
        $subscription = $this->backend()->subscription($this->principal(), 'holidays');

        self::assertNotNull($subscription);
        self::assertSame('holidays', $subscription->name());
        self::assertSame(self::FEED, $subscription->source());
    }

    public function testKnowsNothingAboutOneThatIsNotThere(): void
    {
        self::assertNull($this->backend()->subscription($this->principal(), 'fixtures'));
    }

    /**
     * **The feed is what a subscription is for**, so it comes back exactly as
     * it went in. A store that normalised the URL — dropped a trailing solidus,
     * turned `webcal:` into `https:` — would hand a client something it did not
     * subscribe to, and `…}source` is an href a client compares.
     */
    public function testKeepsOneAndHandsItBack(): void
    {
        $backend = $this->backend();

        $backend->create($this->principal(), new SubscriptionInfo(
            'fixtures',
            'https://example.com/fixtures.ics?team=14',
            'Fixture list',
        ));

        $kept = $backend->subscription($this->principal(), 'fixtures');

        self::assertNotNull($kept);
        self::assertSame('fixtures', $kept->name());
        self::assertSame('https://example.com/fixtures.ics?team=14', $kept->source());
        self::assertSame('Fixture list', $kept->displayName());
    }

    /**
     * **Null is "not set" and never "empty".** A `PROPFIND` for a property
     * nobody set is a `404`; a store answering an empty string instead would be
     * claiming the user named their subscription nothing at all.
     */
    public function testADisplayNameNobodySetIsNotSet(): void
    {
        $backend = $this->backend();

        $backend->create($this->principal(), new SubscriptionInfo('fixtures', self::FEED));

        self::assertNull($backend->subscription($this->principal(), 'fixtures')?->displayName());
    }

    /**
     * Two of one name under one principal is a conflict: the name is the last
     * segment of the collection's URL, and two collections cannot share one.
     */
    public function testANameAlreadyTakenIsRefused(): void
    {
        $this->expectException(Conflict::class);

        $this->backend()->create($this->principal(), new SubscriptionInfo('holidays', self::FEED));
    }

    /**
     * **Two people may subscribe to the same feed under the same name.** The
     * name is inside a principal's home, and a store keyed on the name alone
     * would serve one of them the other's subscription.
     */
    public function testTwoPrincipalsMayBothHaveOneOfThatName(): void
    {
        $backend = $this->backend();
        $theirs = $backend->subscription($this->secondPrincipal(), 'holidays');
        $ours = $backend->subscription($this->principal(), 'holidays');

        self::assertNotNull($theirs);
        self::assertNotNull($ours);
        self::assertNotSame($theirs->source(), $ours->source());
    }

    /**
     * **A feed may be changed**, which is what a `PROPPATCH` of `…}source`
     * comes to: a published calendar moves, and a client that had to delete and
     * re-create the subscription would lose everything else set on it.
     */
    public function testChangesTheFeedOneFollows(): void
    {
        $backend = $this->backend();

        $backend->update($this->principal(), new SubscriptionInfo(
            'holidays',
            'https://example.com/holidays-2027.ics',
            'Public holidays',
        ));

        $changed = $backend->subscription($this->principal(), 'holidays');

        self::assertNotNull($changed);
        self::assertSame('https://example.com/holidays-2027.ics', $changed->source());
        self::assertSame('Public holidays', $changed->displayName());
    }

    /**
     * And changing one that is not there is a mistake the caller hears about,
     * rather than a subscription quietly brought into being.
     */
    public function testChangingOneThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->update($this->principal(), new SubscriptionInfo('fixtures', self::FEED));
    }

    public function testRemovesOne(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'holidays');

        self::assertNull($backend->subscription($this->principal(), 'holidays'));
        self::assertSame([], $backend->subscriptions($this->principal()));
    }

    /**
     * Removing one that is not there is refused, where answering nothing would
     * let a client believe it had unsubscribed from something it still gets.
     */
    public function testRemovingOneThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->remove($this->principal(), 'fixtures');
    }

    /**
     * And removing one takes nobody else's away.
     */
    public function testRemovingOneLeavesTheOtherPrincipalsAlone(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'holidays');

        self::assertNotNull($backend->subscription($this->secondPrincipal(), 'holidays'));
    }

    /**
     * **An empty name is no name**, and neither are the two that mean a
     * directory. A store that took one would write beside the home rather than
     * inside it.
     *
     * @param string $name The name the case is about
     */
    #[DataProvider('namesThatAreNotNames')]
    public function testANameThatIsNoNameIsRefused(string $name): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->create($this->principal(), new SubscriptionInfo($name, self::FEED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotNames(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'this directory' => ['.'];

        yield 'the one above' => ['..'];

        yield 'a path rather than a name' => ['deeper/holidays'];
    }

    /**
     * The same refusal when one is changed, because the name is how a store
     * finds the record and a path is no name wherever it turns up.
     */
    public function testAChangeToANameThatIsNoNameIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->update($this->principal(), new SubscriptionInfo('..', self::FEED));
    }

    public function testRemovingANameThatIsNoNameIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->remove($this->principal(), '..');
    }

    /**
     * **One that merely begins the same way is a different one.** `holidays2`
     * is not `holidays`, and a store that thought otherwise would hand out
     * somebody else's feed.
     */
    public function testOneThatBeginsTheSameWayIsADifferentOne(): void
    {
        self::assertNull($this->backend()->subscription($this->principal(), 'holidays2'));
    }
}
