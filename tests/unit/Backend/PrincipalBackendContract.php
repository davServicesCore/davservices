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

use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use PHPUnit\Framework\TestCase;

/**
 * What every principal storage has to do, whatever it keeps its people in
 * (RFC 3744 §2, R-ACL-01).
 *
 * Written once and run against each implementation, because a backend that
 * is exchangeable is only exchangeable if they all behave the same — and
 * access control asks these questions about every request. A backend that
 * answered "no such principal" where another said yes would hand out
 * different permissions for the same account.
 *
 * **A name nobody has is `null`, not an error.** A path that names no
 * principal is a `404`, and that is the server's word to say; a backend that
 * threw would turn every mistyped URL into a `500`.
 */
abstract class PrincipalBackendContract extends TestCase
{
    public function testHandsBackAPrincipalByName(): void
    {
        $principal = $this->backend()->principal('alice');

        self::assertInstanceOf(PrincipalInfo::class, $principal);
        self::assertSame('alice', $principal->name());
    }

    public function testKnowsNothingOfANameNobodyHas(): void
    {
        self::assertNull($this->backend()->principal('nobody'));
    }

    /**
     * RFC 3744 §4.1: the display name is what a person is called, and it is
     * kept as the backend gave it — a server that invented one from a login
     * would show `a.mueller` to people as though somebody had chosen it.
     */
    public function testKeepsWhatAPersonIsCalled(): void
    {
        self::assertSame('Alice Ashton', $this->backend()->principal('alice')?->displayName());
    }

    public function testAPrincipalNeedNotBeCalledAnything(): void
    {
        self::assertNull($this->backend()->principal('plain')?->displayName());
    }

    /**
     * RFC 3744 §4.1: the alternate URIs are the other ways to reach the same
     * actor, and `mailto:` is the one every calendar client cares about —
     * scheduling finds a person by their address, not by their path.
     */
    public function testKeepsTheOtherWaysToReachThem(): void
    {
        self::assertSame(
            ['mailto:alice@example.test'],
            $this->backend()->principal('alice')?->alternateUris(),
        );
    }

    public function testAPrincipalNeedHaveNoOtherAddress(): void
    {
        self::assertSame([], $this->backend()->principal('plain')?->alternateUris());
    }

    /**
     * **Several addresses, in the order they were written.** A person may be
     * reached at more than one, and a backend that handed over the first
     * would quietly lose the rest — a client looking somebody up by their
     * second address would not find them.
     */
    public function testKeepsEveryOneOfTheirAddresses(): void
    {
        self::assertSame(
            ['mailto:carol@example.test', 'mailto:c.carter@example.test'],
            $this->backend()->principal('carol')?->alternateUris(),
        );
    }

    /**
     * **RFC 3744 §4.4: the groups a principal is *directly* in, and no
     * others.** The wording leaves no room — "identifies the groups in which
     * the principal is **directly** a member" — and §4.4 goes on to tell a
     * client how to find the rest: "the DAV:group-membership of those other
     * groups would need to be queried in order to determine the groups in
     * which the principal is indirectly a member".
     *
     * So a backend that answered transitively would be lying to a client
     * doing exactly what the specification told it to do, and counting some
     * groups twice. Alice is in `staff`; `staff` is in `everyone`; her
     * membership names `staff` alone.
     */
    public function testKeepsTheGroupsAPrincipalIsDirectlyIn(): void
    {
        self::assertSame(['staff'], $this->backend()->principal('alice')?->memberOf());
    }

    /**
     * §4.4 says support for the property is REQUIRED, so there is always an
     * answer: somebody in no group at all is in no group, which is a
     * different statement from the server declining to say.
     */
    public function testAPrincipalNeedBeInNoGroup(): void
    {
        self::assertSame([], $this->backend()->principal('plain')?->memberOf());
    }

    /**
     * And every one of them, in the order they were written — the same rule
     * as for addresses, and the same failure if it is broken: a client
     * checking the second group would be told the person is not in it.
     */
    public function testKeepsEveryGroupTheyAreIn(): void
    {
        self::assertSame(['staff', 'everyone'], $this->backend()->principal('carol')?->memberOf());
    }

    /**
     * **§4.3: the principals that are *direct* members of this group.** The
     * same word again, and for the same reason — "since a group may be a
     * member of another group, a group may also have indirect members (i.e.,
     * the members of its direct members)", which is the specification saying
     * plainly that this property does not include them.
     */
    public function testAGroupKeepsItsDirectMembers(): void
    {
        self::assertSame(['alice', 'carol'], $this->backend()->principal('staff')?->members());
    }

    /**
     * **And a backend need not say at all.** §4.3 is the one property of the
     * four in RFC 3744 §4 that does **not** carry the sentence "Support for
     * this property is REQUIRED" — §4.1, §4.2 and §4.4 all do. A directory
     * that will not hand out group rosters is within its rights, and `null`
     * is how it says so.
     *
     * That is a different answer from an empty list, which says "a group with
     * nobody in it". A backend that returned `[]` for both would tell a
     * client every group it declines to describe is empty.
     */
    public function testABackendNeedNotSayWhoIsInAGroup(): void
    {
        self::assertNull($this->backend()->principal('alice')?->members(), 'not a group, and not claimed to be');
        self::assertSame([], $this->backend()->principal('everyone')?->members(), 'a group nobody is in yet');
    }

    public function testHandsBackEveryPrincipalItHas(): void
    {
        $names = array_map(
            static fn (PrincipalInfo $principal): string => $principal->name(),
            $this->backend()->principals(),
        );

        sort($names);

        self::assertSame(['alice', 'carol', 'everyone', 'plain', 'staff'], $names);
    }

    /**
     * **A listing is a list, not a map.** The interface says so, and a caller
     * that reached for the first of them would find nothing where a backend
     * handed over an array keyed by name — which is exactly the shape a
     * storage keeps its people in internally.
     *
     * The keys are asked about rather than `array_is_list()`, because the
     * static analyser believes the annotation and folds that question away.
     * A backend written elsewhere is not analysed by it, and this contract is
     * for those.
     */
    public function testTheListingIsAList(): void
    {
        self::assertSame([0, 1, 2, 3, 4], array_keys($this->backend()->principals()));
    }

    /**
     * The storage under test, holding Alice — who is called something, has an
     * address and is in `staff` — Carol, who has two addresses and is in two
     * groups, and one plain principal that is nothing but a name.
     *
     * Plus the two groups themselves: `staff`, which says who is in it, and
     * `everyone`, which is a group nobody is in yet. `staff` is a member of
     * `everyone`, so that the contract has a chain to be direct about.
     */
    abstract protected function backend(): IPrincipalBackend;
}
