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

use DavServices\Acl\GroupResolver;
use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §2 and §5.5.1, and R-ACL-04.
 *
 * **Everything the person asking counts as.** §5.5.1: "the current user
 * matches DAV:href only if that user is authenticated as being (**or being a
 * member of**) the principal identified by the URL contained by that
 * DAV:href" — so an entry naming a group applies to everybody in it. And §2
 * settles how far that reaches: "Membership in a group is recursive, so if a
 * principal is a member of group GRPA, and GRPA is a member of group GRPB,
 * then the principal is also a member of GRPB."
 *
 * That is the other half of P3-12a. The **properties** report the direct edge
 * and nothing more, because §4.3 and §4.4 say "direct" in as many words; the
 * **matching** walks the whole chain, because §2 says so. Both are true at
 * once, and keeping them apart is what makes the pair honest.
 *
 * ## The cycle guard is ours, not the specification's
 *
 * RFC 3744 mentions cycles nowhere. It says membership "is recursive" and
 * assumes a graph that ends — which an administrator can break in a minute by
 * putting two groups inside each other. "Recursive" over a loop is undefined,
 * so this server decides: every principal is visited once, which terminates
 * and yields the closure. **Refusing the request instead would lock people
 * out over somebody else's mistake**, and the closure is what "recursive"
 * plainly means where it is defined at all.
 *
 * Unlike `DAV:expand-property`, where the nesting of the request body bounds
 * the recursion, nothing outside bounds this one. The guard is not optional.
 */
#[CoversClass(GroupResolver::class)]
final class GroupResolverTest extends TestCase
{
    /**
     * Somebody in no group counts as themselves and nothing else — and they
     * are in the answer, because §5.5.1 matches a principal "as being" the
     * one an entry names, not only as a member of it.
     */
    public function testSomebodyInNoGroupIsStillThemselves(): void
    {
        self::assertSame(['principals/plain'], $this->resolver()->identitiesOf('principals/plain'));
    }

    /**
     * §5.5.1: an entry naming a group applies to a member of it, so the group
     * is one of the things the asker counts as.
     */
    public function testAMemberCountsAsTheirGroup(): void
    {
        self::assertSame(
            ['principals/alice', 'principals/staff', 'principals/everyone'],
            $this->resolver()->identitiesOf('principals/alice'),
        );
    }

    /**
     * **§2, in the specification's own example:** alice is in `staff`,
     * `staff` is in `everyone`, so alice is in `everyone` too. A check that
     * stopped at the first level would grant far less than whoever wrote the
     * entry intended.
     */
    public function testMembershipReachesThroughAGroupIntoItsGroups(): void
    {
        self::assertContains('principals/everyone', $this->resolver()->identitiesOf('principals/alice'));
    }

    /**
     * The principal itself comes first, then the groups outward. The order is
     * fixed rather than left to chance, because it is the order privileges
     * are merged in and a set that came out differently between requests
     * would be a server nobody can reason about.
     */
    public function testTheAskerComesFirstAndTheGroupsOutward(): void
    {
        self::assertSame(
            ['principals/carol', 'principals/staff', 'principals/everyone'],
            $this->resolver()->identitiesOf('principals/carol'),
        );
    }

    /**
     * **Nobody is named twice.** Carol is in `staff` and in `everyone`, and
     * `staff` is in `everyone` as well — the same group reached two ways is
     * still one identity, and asking the resolver about it twice would be one
     * query for nothing.
     */
    public function testAGroupReachedTwoWaysIsNamedOnce(): void
    {
        $identities = $this->resolver()->identitiesOf('principals/carol');

        self::assertSame(array_values(array_unique($identities)), $identities);
    }

    /**
     * **Two groups inside each other terminate.** This is the guard the
     * specification does not ask for and the graph cannot do without: an
     * administrator who puts `loop-a` in `loop-b` and `loop-b` in `loop-a`
     * has made something "recursive" has no answer to, and a server that
     * followed it would answer no request at all.
     */
    public function testTwoGroupsInsideEachOtherTerminate(): void
    {
        self::assertSame(
            ['principals/loop-a', 'principals/loop-b'],
            $this->resolver()->identitiesOf('principals/loop-a'),
        );
    }

    /**
     * And a group that contains itself is the same mistake, written shorter.
     */
    public function testAGroupInsideItselfTerminates(): void
    {
        self::assertSame(['principals/ouroboros'], $this->resolver()->identitiesOf('principals/ouroboros'));
    }

    /**
     * **A path that is no principal of ours counts as itself and nothing
     * more.** A deployment may name something else in an access control
     * entry, and this resolver has no business inventing groups for it.
     */
    public function testAPathThatIsNoPrincipalHereIsLeftAlone(): void
    {
        self::assertSame(['calendars/work.ics'], $this->resolver()->identitiesOf('calendars/work.ics'));
    }

    /**
     * Nor does a name that looks like one of ours but is not: a principal
     * this backend has never heard of is still just itself.
     */
    public function testANameTheBackendDoesNotKnowIsLeftAlone(): void
    {
        self::assertSame(['principals/nobody'], $this->resolver()->identitiesOf('principals/nobody'));
    }

    /**
     * The collection is wherever the application mounted it, and nothing here
     * assumes `/principals` — the same rule the rest of this library keeps.
     */
    public function testThePrincipalCollectionIsWhereverItWasMounted(): void
    {
        $resolver = new GroupResolver($this->backend(), 'dav/people');

        self::assertSame(
            ['dav/people/alice', 'dav/people/staff', 'dav/people/everyone'],
            $resolver->identitiesOf('dav/people/alice'),
        );
    }

    private function resolver(): GroupResolver
    {
        return new GroupResolver($this->backend(), 'principals');
    }

    /**
     * Alice is in `staff`, `staff` is in `everyone`; Carol is in both, so the
     * same group is reached two ways. And two pairs of groups that eat their
     * own tails, because somebody will build one.
     */
    private function backend(): IPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', [], ['staff']),
            new PrincipalInfo('carol', 'Carol Carter', [], ['staff', 'everyone']),
            new PrincipalInfo('plain'),
            new PrincipalInfo('staff', 'The staff', [], ['everyone'], ['alice', 'carol']),
            new PrincipalInfo('everyone', 'Everybody here', [], [], ['staff']),
            new PrincipalInfo('loop-a', 'One half of a mistake', [], ['loop-b']),
            new PrincipalInfo('loop-b', 'The other half', [], ['loop-a']),
            new PrincipalInfo('ouroboros', 'A group in itself', [], ['ouroboros']),
        );
    }
}
