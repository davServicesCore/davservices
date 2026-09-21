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

use DavServices\Acl\Principal;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Exception\Forbidden;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §4 and R-ACL-01.
 *
 * **A principal is a resource, so that a client can ask about it.** That is
 * the whole idea of RFC 3744: rather than a private notion of users, the
 * people a server knows are addressable, and a `PROPFIND` on one answers what
 * it is called and how else to reach it.
 *
 * Two of its properties are answered here, and one is not. **`displayname`
 * and `alternate-URI-set` the node knows** — they came from the backend with
 * it. **`principal-URL` it cannot know**, because a node knows its name and
 * nothing about where it hangs (see {@see \DavServices\Dav\INode}); that one
 * is answered where the path is known, by the plugin.
 *
 * `DAV:resourcetype` carries `DAV:principal`, which is how a client tells one
 * from any other resource, and it is said through
 * {@see \DavServices\Dav\IResourceType} rather than as a property of its own:
 * listeners answer `resourcetype` before a node is asked.
 */
#[CoversClass(Principal::class)]
final class PrincipalTest extends TestCase
{
    public function testIsKnownByTheNameItWasGiven(): void
    {
        self::assertSame('alice', $this->principal()->name());
    }

    /**
     * RFC 3744 §4: a principal is of `DAV:principal` resource type, and a
     * client that could not tell one from a file would have nothing to point
     * an access control entry at.
     */
    public function testSaysThatItIsAPrincipal(): void
    {
        self::assertSame(['{DAV:}principal'], $this->principal()->resourceTypes());
    }

    /**
     * **`displayname` is the backend's to say, not the server's.** A name
     * invented from a login would be shown to people as though somebody had
     * chosen it — which is why {@see \DavServices\Dav\Property\LiveProperties}
     * deliberately leaves it alone.
     */
    public function testHandsOverWhatThePersonIsCalled(): void
    {
        self::assertSame(
            ['{DAV:}displayname' => 'Alice Ashton'],
            $this->principal()->properties(['{DAV:}displayname']),
        );
    }

    /**
     * RFC 3744 §4.1: the other URIs the same actor answers to, as hrefs. A
     * calendar client looks a person up by their address rather than by their
     * path, and this is where it finds one.
     */
    public function testHandsOverTheOtherWaysToReachThem(): void
    {
        $answers = $this->principal()->properties(['{DAV:}alternate-URI-set']);
        $set = $answers['{DAV:}alternate-URI-set'] ?? null;

        self::assertInstanceOf(Element::class, $set);
        self::assertCount(1, $set->children());

        $href = $set->children()[0] ?? self::fail('The set holds no href.');

        self::assertSame('{DAV:}href', $href->name());
        self::assertSame('mailto:alice@example.test', $href->text());
    }

    /**
     * A principal nobody has named is **left out** rather than answered with
     * null: null is a value a property may hold, and "we have nothing here"
     * is a different thing from "this is empty".
     */
    public function testLeavesOutANameNobodyHasGiven(): void
    {
        self::assertSame([], $this->plain()->properties(['{DAV:}displayname']));
    }

    /**
     * An empty `alternate-URI-set` is the honest answer where a principal has
     * no other address: RFC 3744 §4.1 has every principal carry the property,
     * and an empty set says "none" where a missing one says "this server does
     * not know the question".
     */
    public function testSaysThatThereIsNoOtherWayToReachThem(): void
    {
        $set = $this->plain()->properties(['{DAV:}alternate-URI-set'])['{DAV:}alternate-URI-set'] ?? null;

        self::assertInstanceOf(Element::class, $set);
        self::assertSame([], $set->children());
    }

    /**
     * **`principal-URL` is not answered here**, because a node knows its name
     * and nothing about where it hangs. The plugin answers it where the path
     * is known, and a node that guessed would send clients to a place that
     * does not answer.
     */
    public function testDoesNotClaimToKnowItsOwnUrl(): void
    {
        self::assertSame([], $this->principal()->properties(['{DAV:}principal-URL']));
    }

    public function testSaysWhichPropertiesItHas(): void
    {
        self::assertSame(
            ['{DAV:}displayname', '{DAV:}alternate-URI-set'],
            $this->principal()->propertyNames(),
        );
    }

    /**
     * And one with no name to give does not offer `displayname` under
     * `propname` either: the list is what there is, not what there could be.
     */
    public function testOffersNoNameItDoesNotHave(): void
    {
        self::assertSame(['{DAV:}alternate-URI-set'], $this->plain()->propertyNames());
    }

    /**
     * **A principal is not edited through WebDAV here.** Whoever exists is
     * the application's business — a directory, an identity provider — and a
     * `PROPPATCH` that appeared to rename somebody while the directory kept
     * the old name would be worse than a refusal.
     */
    public function testRefusesToHaveItsPropertiesChanged(): void
    {
        self::assertSame(
            ['{DAV:}displayname' => 403],
            $this->principal()->patchProperties(['{DAV:}displayname' => 'Someone Else']),
        );
    }

    /**
     * And it is not deleted through WebDAV either, for the same reason.
     */
    public function testRefusesToBeDeleted(): void
    {
        $this->expectException(Forbidden::class);

        $this->principal()->delete();
    }

    /**
     * A backend that cannot say when a principal last changed says nothing
     * rather than guessing: an invented time would be handed out as
     * `DAV:getlastmodified` and cached by clients.
     */
    public function testSaysNothingAboutWhenItLastChanged(): void
    {
        self::assertNull($this->principal()->lastModified());
    }

    private function principal(): Principal
    {
        return new Principal(new PrincipalInfo('alice', 'Alice Ashton', ['mailto:alice@example.test']));
    }

    private function plain(): Principal
    {
        return new Principal(new PrincipalInfo('plain'));
    }
}
