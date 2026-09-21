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

namespace DavServices\Tests\Unit\Acl;

use DavServices\Acl\Privilege;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §3 and R-ACL-02.
 *
 * **A privilege is a tree, and the tree is the whole of the meaning.**
 * `DAV:write` is not a permission of its own so much as a name for four
 * others; a server that granted it without granting `DAV:bind` would let a
 * client change a file it may not create. Aggregation is what makes an access
 * control list short enough for a person to write.
 *
 * Two things are settled here and both matter later.
 *
 * **It is transitive.** `DAV:all` reaches `DAV:write-content` through
 * `DAV:write`, and nothing in the checking code should have to walk that
 * itself.
 *
 * **It can be extended.** `CALDAV:read-free-busy` hangs under `DAV:read`
 * (RFC 4791 §6.1.1), and CalDAV is a layer **above** this one — so the tree
 * cannot name it, and whoever does has to be able to put it there. A tree
 * that could not grow would force every extension's privileges into the core,
 * which is the opposite of what R-ARC-02 asks.
 *
 * The order the tree is walked in is the order `DAV:supported-privilege-set`
 * will be written in (P3-09), so it is fixed here rather than left to chance.
 */
#[CoversClass(Privilege::class)]
final class PrivilegeTest extends TestCase
{
    private const FREE_BUSY = '{urn:ietf:params:xml:ns:caldav}read-free-busy';

    public function testIsKnownByItsName(): void
    {
        self::assertSame('{DAV:}bind', (new Privilege('{DAV:}bind'))->name());
    }

    /**
     * A privilege that aggregates nothing is itself and nothing more — most
     * of them are like that, and they are what an access check asks about.
     */
    public function testAPrivilegeOfItsOwnReachesOnlyItself(): void
    {
        self::assertSame(['{DAV:}bind'], (new Privilege('{DAV:}bind'))->flattened());
    }

    public function testKnowsWhatItAggregatesDirectly(): void
    {
        $write = Privilege::standard()->find('{DAV:}write');

        self::assertNotNull($write);
        self::assertSame(
            ['{DAV:}write-content', '{DAV:}write-properties', '{DAV:}bind', '{DAV:}unbind'],
            array_map(static fn (Privilege $child): string => $child->name(), $write->aggregates()),
        );
    }

    /**
     * R-ACL-02 and R-PRIV-06: **`DAV:all` reaches `DAV:write-content` through
     * `DAV:write`.** Nothing that checks access should have to walk the tree
     * itself, and a check that stopped at the first level would grant far
     * less than an administrator wrote down.
     */
    #[DataProvider('whatAllReaches')]
    public function testAggregationIsTransitive(string $name): void
    {
        self::assertTrue(Privilege::standard()->contains($name), sprintf('DAV:all reaches %s', $name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatAllReaches(): iterable
    {
        foreach ([
            '{DAV:}read',
            '{DAV:}write',
            '{DAV:}write-content',
            '{DAV:}write-properties',
            '{DAV:}bind',
            '{DAV:}unbind',
            '{DAV:}unlock',
            '{DAV:}read-acl',
            '{DAV:}write-acl',
            '{DAV:}read-current-user-privilege-set',
            '{DAV:}all',
        ] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * And it reaches nothing it was not given: a name nobody put in the tree
     * is not a privilege of this server, however plausible it looks.
     */
    public function testReachesNothingItWasNotGiven(): void
    {
        self::assertFalse(Privilege::standard()->contains('{DAV:}write-everything'));
    }

    /**
     * `DAV:write` reaches its four and **not** the ones beside it: a client
     * given write may change and create, and may not rewrite the access
     * control list.
     */
    public function testWriteDoesNotReachTheAclPrivileges(): void
    {
        $write = Privilege::standard()->find('{DAV:}write');

        self::assertNotNull($write);
        self::assertTrue($write->contains('{DAV:}bind'));
        self::assertFalse($write->contains('{DAV:}write-acl'));
        self::assertFalse($write->contains('{DAV:}read'));
    }

    /**
     * RFC 3744 §3.5: `DAV:unlock` is a privilege of its own, because taking
     * somebody else's lock away is not the same as writing. It hangs under
     * `DAV:all` and under nothing else.
     */
    public function testUnlockIsItsOwnPrivilege(): void
    {
        $write = Privilege::standard()->find('{DAV:}write');

        self::assertNotNull($write);
        self::assertFalse($write->contains('{DAV:}unlock'));
    }

    public function testFindsAPrivilegeAnywhereInTheTree(): void
    {
        self::assertSame('{DAV:}bind', Privilege::standard()->find('{DAV:}bind')?->name());
    }

    public function testFindsNothingForANameNobodyHas(): void
    {
        self::assertNull(Privilege::standard()->find('{DAV:}write-everything'));
    }

    /**
     * **CalDAV hangs its own privilege under `DAV:read`** (RFC 4791 §6.1.1),
     * and CalDAV is a layer above this one — so the tree cannot name it and
     * whoever does has to be able to put it there.
     */
    public function testTakesAPrivilegeAnExtensionBringsWithIt(): void
    {
        $tree = Privilege::standard()->with('{DAV:}read', new Privilege(self::FREE_BUSY));

        self::assertTrue($tree->contains(self::FREE_BUSY));
        self::assertTrue($tree->find('{DAV:}read')?->contains(self::FREE_BUSY) ?? false);
    }

    /**
     * **And what the parent already aggregated stays.** Hanging something
     * under `DAV:write` must not cost it its four — a tree that replaced
     * them would quietly turn `DAV:write` into a name for one thing, and
     * every client granted it would lose the right to create members.
     */
    public function testKeepsWhatTheParentAlreadyAggregated(): void
    {
        $tree = Privilege::standard()->with('{DAV:}write', new Privilege(self::FREE_BUSY));
        $write = $tree->find('{DAV:}write');

        self::assertNotNull($write);
        self::assertSame(
            ['{DAV:}write-content', '{DAV:}write-properties', '{DAV:}bind', '{DAV:}unbind', self::FREE_BUSY],
            array_map(static fn (Privilege $child): string => $child->name(), $write->aggregates()),
        );
    }

    /**
     * Two extensions hang their own under the same parent, and the second
     * does not cost the first its place.
     */
    public function testTakesOnePrivilegeAfterAnother(): void
    {
        $tree = Privilege::standard()
            ->with('{DAV:}read', new Privilege(self::FREE_BUSY))
            ->with('{DAV:}read', new Privilege('{https://dav.services/test}read-something'));

        self::assertTrue($tree->contains(self::FREE_BUSY));
        self::assertTrue($tree->contains('{https://dav.services/test}read-something'));
    }

    /**
     * And the tree it was made from is unchanged, because nothing in this
     * library hands out an object that changes under whoever is holding it.
     */
    public function testLeavesTheTreeItWasMadeFromAlone(): void
    {
        $tree = Privilege::standard();

        $tree->with('{DAV:}read', new Privilege(self::FREE_BUSY));

        self::assertFalse($tree->contains(self::FREE_BUSY));
    }

    /**
     * A privilege hung under a parent nobody has is a mistake in the code
     * that hung it, and it is said out loud: silently keeping a tree that
     * does not hold what somebody put in it would grant nothing and explain
     * nothing.
     */
    public function testRefusesToHangAPrivilegeUnderAParentNobodyHas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Privilege::standard()->with('{DAV:}write-everything', new Privilege(self::FREE_BUSY));
    }

    /**
     * **The order is the tree, and it is fixed.**
     * `DAV:supported-privilege-set` is written from this walk, and a client
     * reading it is being shown the shape of what this server grants.
     */
    public function testWalksItselfBeforeWhatItAggregates(): void
    {
        $flattened = Privilege::standard()->flattened();

        self::assertSame('{DAV:}all', $flattened[0] ?? null);
        self::assertLessThan(
            array_search('{DAV:}write-content', $flattened, true),
            array_search('{DAV:}write', $flattened, true),
        );
    }

    /**
     * Every privilege appears once. A tree that named one twice would have
     * `supported-privilege-set` say it twice, and a client counting would be
     * told something that is not so.
     */
    public function testNamesEachPrivilegeOnce(): void
    {
        $flattened = Privilege::standard()->flattened();

        self::assertSame(array_values(array_unique($flattened)), $flattened);
    }
}
