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

namespace DavServices\Tests\Unit\Dav\Precondition;

use DavServices\Dav\Event\StateTokensRequested;
use DavServices\Dav\Precondition\RequestConditions;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §10.4 and R-HTTP-07.
 *
 * **A request that put a condition on itself is held to it, and that is core
 * WebDAV rather than part of locking.** An `If` header may name entity tags
 * alone, and those need no lock storage to check. A server assembled without
 * the lock plugin that ignored such a header would be dropping a guard a
 * client took pains to set — which is what this library refuses to do
 * elsewhere: {@see \DavServices\Http\IfHeader} answers `400` to a header it
 * cannot read for exactly that reason.
 *
 * The state tokens come in through an event, so that this knows nothing about
 * locks (R-ARC-02). With no listener there are no tokens, and a condition
 * naming one is simply false — which is the right answer: a client that
 * submitted a lock token to a server that holds no locks has claimed
 * something untrue.
 *
 * **Fail closed throughout.** A resource tag naming another server, or a path
 * that cannot be resolved, yields a state that satisfies nothing. A condition
 * this server cannot check is not one it may call true.
 */
#[CoversClass(RequestConditions::class)]
final class RequestConditionsTest extends TestCase
{
    private const TOKEN = 'opaquelocktoken:held';

    public function testARequestThatClaimsNothingIsHeldToNothing(): void
    {
        $this->refuse(new Request('PUT', '/calendars/work.ics'));

        self::expectNotToPerformAssertions();
    }

    /**
     * The entity tag half of `If`, which needs no locking at all — and is the
     * whole reason this lives in the core.
     */
    public function testARequestNamingTheEntityTagTheResourceHasPasses(): void
    {
        $this->refuse($this->asking(sprintf('(["%s"])', md5('BEGIN:VCALENDAR'))));

        self::expectNotToPerformAssertions();
    }

    public function testARequestNamingAnEntityTagTheResourceHasNotIsRefused(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse($this->asking('(["a tag this resource has never had"])'));
    }

    /**
     * **With nobody contributing tokens, a condition about one is false.**
     * That is the right answer rather than a lenient one: a client that
     * submitted a lock token to a server which holds no locks has claimed
     * something untrue about the resource.
     */
    public function testARequestNamingAStateTokenNobodyContributesIsRefused(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse($this->asking(sprintf('(<%s>)', self::TOKEN)));
    }

    /**
     * And a listener that names the token makes the same condition hold. This
     * is the seam the lock plugin answers on, exercised without a lock in
     * sight.
     */
    public function testARequestNamingAStateTokenAListenerContributesPasses(): void
    {
        $events = new EventEmitter();

        $events->on(StateTokensRequested::class, static function (StateTokensRequested $event): void {
            $event->add(self::TOKEN);
        });

        $this->refuse($this->asking(sprintf('(<%s>)', self::TOKEN)), $events);

        self::expectNotToPerformAssertions();
    }

    /**
     * The listener is asked about the path the condition is about, not about
     * some other one: a contributor has to know which resource it is being
     * asked about or it cannot answer at all.
     */
    public function testAsksTheListenerAboutThePathTheConditionIsAbout(): void
    {
        $asked = [];
        $events = new EventEmitter();

        $events->on(StateTokensRequested::class, static function (StateTokensRequested $event) use (&$asked): void {
            $asked[] = $event->path();
            $event->add(self::TOKEN);
        });

        $this->refuse($this->asking(sprintf('(<%s>)', self::TOKEN)), $events);

        self::assertSame(['calendars/work.ics'], $asked);
    }

    /**
     * RFC 4918 §10.4.2: a tagged list is about the resource it names, and the
     * URL in the tag has to become a path in this tree before anything can be
     * asked about it.
     */
    public function testATaggedListIsAboutTheResourceItNames(): void
    {
        $asked = [];
        $events = new EventEmitter();

        $events->on(StateTokensRequested::class, static function (StateTokensRequested $event) use (&$asked): void {
            $asked[] = $event->path();
            $event->add(self::TOKEN);
        });

        $this->refuse(new Request('PUT', '/calendars/work.ics', new Headers([
            'Host' => 'localhost',
            'If' => sprintf('<http://localhost/calendars/other.ics> (<%s>)', self::TOKEN),
        ])), $events);

        self::assertSame(['calendars/other.ics'], $asked);
    }

    /**
     * **A condition this server cannot check is not one it may call true.** A
     * tag naming another server names a resource this one knows nothing
     * about, and the safe reading is that the list does not hold.
     */
    public function testAConditionAboutAnotherServerHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse(new Request('PUT', '/calendars/work.ics', new Headers([
            'Host' => 'localhost',
            'If' => sprintf('<http://elsewhere.example/calendars/work.ics> (<%s>)', self::TOKEN),
        ])));
    }

    public function testAConditionAboutAPathNobodyCanResolveHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse($this->asking(sprintf('</../outside> (<%s>)', self::TOKEN)));
    }

    /**
     * A path that holds nothing has no entity tag, so a condition naming one
     * cannot hold: a `PUT` that would create a file cannot name a tag the
     * file does not have yet.
     */
    public function testAConditionAboutAPathThatHoldsNothingHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse(new Request('PUT', '/calendars/new.ics', new Headers(['If' => '(["whatever"])'])));
    }

    /**
     * And a collection has no entity tag of its own.
     */
    public function testAConditionAboutTheEntityTagOfACollectionHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->refuse(new Request('DELETE', '/calendars', new Headers(['If' => '(["whatever"])'])));
    }

    /**
     * RFC 4918 §10.4 read through {@see \DavServices\Http\IfHeader}: a header
     * nobody can read is a `400`, because a condition quietly dropped could
     * let through exactly the write the client took pains to prevent.
     */
    public function testAHeaderNobodyCanReadIsARequestNobodyCanAnswer(): void
    {
        $this->expectException(BadRequest::class);

        $this->refuse($this->asking('nonsense'));
    }

    private function asking(string $header): Request
    {
        return new Request('PUT', '/calendars/work.ics', new Headers(['If' => $header]));
    }

    private function refuse(Request $request, ?EventEmitter $events = null): void
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('other.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        $server = new Server(new Tree($root), $events);

        (new RequestConditions($server))->refuseWhatDoesNotHold($request);
    }
}
