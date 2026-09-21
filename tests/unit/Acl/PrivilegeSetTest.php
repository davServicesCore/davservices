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
use DavServices\Acl\PrivilegeSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-PRIV-06, R-PRIV-03 and RFC 3744 §5.4.
 *
 * What a principal actually holds on one resource. Everything that decides
 * whether a request may go through asks this object, and it has to answer
 * without anybody having to know the shape of the privilege tree.
 *
 * **Aggregation happens once, when the set is made.** Granting `DAV:write`
 * grants its four, and from then on `has()` is a lookup rather than a walk.
 * That matters for more than tidiness: a `PROPFIND` of two hundred members
 * asks this two hundred times.
 *
 * **An empty set is "no access", and it is a proper answer** (R-PRIV-03). A
 * request from nobody in particular gets one, and every check has to read it
 * as a refusal rather than as a missing answer.
 *
 * **What was granted and what that reaches are both kept.** `DAV:acl` reports
 * the entries as somebody wrote them, while
 * `DAV:current-user-privilege-set` reports what they come to — the same set
 * answering two different questions.
 *
 * **A name that is in no tree is refused** rather than kept. A set holding a
 * privilege this server does not grant would answer `has()` for it and let a
 * request through on a permission nobody can have revoked.
 */
#[CoversClass(PrivilegeSet::class)]
final class PrivilegeSetTest extends TestCase
{
    private const FREE_BUSY = '{urn:ietf:params:xml:ns:caldav}read-free-busy';

    /**
     * R-PRIV-03: not being signed in is answered with an empty set, and an
     * empty set holds nothing at all.
     */
    public function testAnEmptySetHoldsNothing(): void
    {
        $set = PrivilegeSet::nothing();

        self::assertTrue($set->isEmpty());
        self::assertFalse($set->has('{DAV:}read'));
        self::assertSame([], $set->granted());
        self::assertSame([], $set->flattened());
    }

    public function testHoldsWhatItWasGranted(): void
    {
        $set = $this->set('{DAV:}read');

        self::assertFalse($set->isEmpty());
        self::assertTrue($set->has('{DAV:}read'));
        self::assertSame(['{DAV:}read'], $set->granted());
    }

    /**
     * **R-PRIV-06, and the reason this object exists.** A client granted
     * `DAV:write` may change a file, set a property, create a member and
     * remove one — and nothing checking any of those should have to know
     * that.
     */
    public function testGrantingWriteGrantsTheFourItAggregates(): void
    {
        $set = $this->set('{DAV:}write');

        self::assertTrue($set->has('{DAV:}write-content'));
        self::assertTrue($set->has('{DAV:}write-properties'));
        self::assertTrue($set->has('{DAV:}bind'));
        self::assertTrue($set->has('{DAV:}unbind'));
    }

    public function testGrantingAllGrantsEverythingThereIs(): void
    {
        $set = $this->set('{DAV:}all');

        self::assertTrue($set->has('{DAV:}write-content'));
        self::assertTrue($set->has('{DAV:}read-acl'));
        self::assertTrue($set->has('{DAV:}unlock'));
    }

    /**
     * And granting one of the four grants **only** that one: an aggregate
     * reaches downwards and never up, or every `DAV:bind` would be a
     * `DAV:write`.
     */
    public function testGrantingOneOfThemGrantsNoneOfTheOthers(): void
    {
        $set = $this->set('{DAV:}bind');

        self::assertTrue($set->has('{DAV:}bind'));
        self::assertFalse($set->has('{DAV:}write'));
        self::assertFalse($set->has('{DAV:}unbind'));
    }

    /**
     * **Two questions, one set.** `DAV:acl` reports what was granted, as
     * somebody wrote it; `DAV:current-user-privilege-set` reports what that
     * comes to. Keeping only the second would lose what an administrator
     * actually put in the list.
     */
    public function testKeepsWhatWasGrantedApartFromWhatItReaches(): void
    {
        $set = $this->set('{DAV:}write');

        self::assertSame(['{DAV:}write'], $set->granted());
        self::assertSame(
            ['{DAV:}write', '{DAV:}write-content', '{DAV:}write-properties', '{DAV:}bind', '{DAV:}unbind'],
            $set->flattened(),
        );
    }

    public function testTakesSeveralPrivilegesAtOnce(): void
    {
        $set = $this->set('{DAV:}read', '{DAV:}bind');

        self::assertSame(['{DAV:}read', '{DAV:}bind'], $set->granted());
        self::assertTrue($set->has('{DAV:}read'));
        self::assertTrue($set->has('{DAV:}bind'));
    }

    /**
     * **Two sets join without repeating themselves.** A principal may be in
     * two groups that grant overlapping privileges, and a set that named
     * `DAV:read` twice would have `current-user-privilege-set` say it twice.
     */
    public function testJoinsWithAnotherSet(): void
    {
        $joined = $this->set('{DAV:}read')->merged($this->set('{DAV:}write', '{DAV:}read'));

        self::assertSame(['{DAV:}read', '{DAV:}write'], $joined->granted());
        self::assertTrue($joined->has('{DAV:}bind'));
    }

    public function testJoiningWithNothingChangesNothing(): void
    {
        $set = $this->set('{DAV:}read');

        self::assertSame(['{DAV:}read'], $set->merged(PrivilegeSet::nothing())->granted());
        self::assertSame(['{DAV:}read'], PrivilegeSet::nothing()->merged($set)->granted());
    }

    /**
     * And the set it was joined to is unchanged: nothing here hands out an
     * object that changes under whoever is holding it.
     */
    public function testLeavesTheSetsItJoinedAlone(): void
    {
        $one = $this->set('{DAV:}read');
        $other = $this->set('{DAV:}write');

        $one->merged($other);

        self::assertFalse($one->has('{DAV:}write'));
        self::assertFalse($other->has('{DAV:}read'));
    }

    /**
     * **A privilege this server does not grant cannot be held.** A set that
     * kept an unknown name would answer `has()` for it, and a request would
     * go through on a permission nobody could ever have revoked — because it
     * is in no list, no report and no tree.
     */
    public function testRefusesAPrivilegeThatIsInNoTree(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->set('{DAV:}write-everything');
    }

    /**
     * Asking about an unknown name is **not** an error, though: a client may
     * ask whatever it likes, and the answer is simply no.
     */
    public function testAnsweringAboutAnUnknownPrivilegeIsSimplyNo(): void
    {
        self::assertFalse($this->set('{DAV:}all')->has('{DAV:}write-everything'));
    }

    /**
     * A set is made against the tree it belongs to, so a server whose CalDAV
     * plugin added `CALDAV:read-free-busy` can grant it — and `DAV:read`
     * reaches it, because that is where it hangs.
     */
    public function testWorksWithAPrivilegeAnExtensionBrought(): void
    {
        $tree = Privilege::standard()->with('{DAV:}read', new Privilege(self::FREE_BUSY));
        $set = PrivilegeSet::of($tree, '{DAV:}read');

        self::assertTrue($set->has(self::FREE_BUSY));
    }

    private function set(string ...$names): PrivilegeSet
    {
        return PrivilegeSet::of(Privilege::standard(), ...$names);
    }
}
