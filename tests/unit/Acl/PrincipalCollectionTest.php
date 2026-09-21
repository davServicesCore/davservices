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
use DavServices\Acl\PrincipalCollection;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §5.8 and R-ACL-01.
 *
 * Where the principals hang. A collection like any other as far as the
 * protocol is concerned — which is the point: a client walks it with
 * `PROPFIND` and needs to learn nothing new to do so.
 *
 * **Nothing is created or removed through it.** Who exists is the
 * application's business, and a `PUT` into this collection that appeared to
 * make a person would make nothing at all.
 *
 * **A listing may be refused**, and a deployment with a hundred thousand
 * users will refuse it. That decision belongs to the backend, which is the
 * only thing that knows how many there are; the collection passes it on.
 */
#[CoversClass(PrincipalCollection::class)]
final class PrincipalCollectionTest extends TestCase
{
    public function testIsKnownByTheNameItWasGiven(): void
    {
        self::assertSame('principals', $this->collection()->name());
    }

    public function testHandsOverOnePrincipalByName(): void
    {
        $member = $this->collection()->child('alice');

        self::assertInstanceOf(Principal::class, $member);
        self::assertSame('alice', $member->name());
    }

    /**
     * A path that names no principal is a `404`, which is the server's word
     * to say about a mistyped URL.
     */
    public function testKnowsNothingOfANameNobodyHas(): void
    {
        $this->expectException(NotFound::class);

        $this->collection()->child('nobody');
    }

    public function testSaysWhetherItHasAMember(): void
    {
        self::assertTrue($this->collection()->hasChild('alice'));
        self::assertFalse($this->collection()->hasChild('nobody'));
    }

    public function testListsEveryPrincipalItHas(): void
    {
        $names = array_map(
            static fn (object $member): string => $member->name(),
            $this->collection()->children(),
        );

        sort($names);

        self::assertSame(['alice', 'plain'], $names);
    }

    /**
     * **The refusal is the backend's, and it is passed on rather than
     * softened.** A collection that answered an empty listing where the
     * storage said no would tell a client there are no users at all.
     */
    public function testPassesOnAStorageThatWillNotBeListed(): void
    {
        $backend = new MemoryPrincipalBackend(new PrincipalInfo('alice'));

        $backend->refuseListing();

        $this->expectException(Forbidden::class);

        (new PrincipalCollection('principals', $backend))->children();
    }

    /**
     * Who exists is the application's business. A `PUT` that appeared to make
     * a person would make nothing, and a client told `201` would go on
     * believing in somebody who is not there.
     */
    public function testRefusesToHaveAFileMadeInIt(): void
    {
        $this->expectException(Forbidden::class);

        $this->collection()->createFile('somebody');
    }

    public function testRefusesToHaveACollectionMadeInIt(): void
    {
        $this->expectException(Forbidden::class);

        $this->collection()->createCollection('somebody');
    }

    public function testRefusesToBeDeleted(): void
    {
        $this->expectException(Forbidden::class);

        $this->collection()->delete();
    }

    public function testSaysNothingAboutWhenItLastChanged(): void
    {
        self::assertNull($this->collection()->lastModified());
    }

    private function collection(): PrincipalCollection
    {
        return new PrincipalCollection('principals', new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton'),
            new PrincipalInfo('plain'),
        ));
    }
}
