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

namespace DavServices\Tests\Unit\Dav\Locks;

use DavServices\Dav\Locks\IfEvaluator;
use DavServices\Dav\Locks\ResourceState;
use DavServices\Http\ETag;
use DavServices\Http\IfHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §10.4.3 and §10.4.4, for R-HTTP-07.
 *
 * The grammar was taken apart in P1-09; this is the other half, and the half
 * that decides. **The header is a list of lists: every condition inside a
 * list has to hold, and one list holding is enough for the header.** An *and*
 * inside an *or*, and getting either of them the wrong way round turns a
 * guard a client put on its write into no guard at all.
 *
 * A list may be tagged with the resource it is about, and the tag holds for
 * the lists that follow it until the next one — that much the parser settled.
 * What is settled here is that each list is held against **its own** resource:
 * a client writing to a collection may say what it knows about a member, and a
 * server that checked every condition against the request target would refuse
 * it for no reason.
 *
 * Where the resource comes from is handed in. Only the server knows where it
 * is mounted, and only the lock plugin knows what is held — this class knows
 * neither, which is what makes the truth table testable on its own.
 */
#[CoversClass(IfEvaluator::class)]
final class IfEvaluatorTest extends TestCase
{
    private const TOKEN = 'opaquelocktoken:held';

    public function testOneConditionThatHoldsIsEnough(): void
    {
        self::assertTrue($this->holds(sprintf('(<%s>)', self::TOKEN)));
    }

    public function testOneConditionThatDoesNotHoldIsNotEnough(): void
    {
        self::assertFalse($this->holds('(<opaquelocktoken:other>)'));
    }

    /**
     * **Every condition in a list has to hold.** A client that sent its lock
     * token *and* the entity tag it saw is saying "only if both are still
     * so", and one of them being true is not what it asked for.
     */
    public function testEveryConditionInOneListHasToHold(): void
    {
        self::assertTrue($this->holds(sprintf('(<%s> ["abc"])', self::TOKEN)));
        self::assertFalse($this->holds(sprintf('(<%s> ["stale"])', self::TOKEN)));
        self::assertFalse($this->holds('(<opaquelocktoken:other> ["abc"])'));
    }

    /**
     * **And one list holding is enough for the header.** A client that knows
     * several ways the resource might still be what it saw may name them all,
     * and any of them being so lets the write through.
     */
    public function testOneListOfSeveralHoldingIsEnough(): void
    {
        self::assertTrue($this->holds(sprintf('(["stale"]) (<%s>)', self::TOKEN)));
        self::assertTrue($this->holds(sprintf('(<%s>) (["stale"])', self::TOKEN)));
    }

    public function testNoListHoldingIsAHeaderThatDoesNotHold(): void
    {
        self::assertFalse($this->holds('(["stale"]) (<opaquelocktoken:other>)'));
    }

    /**
     * RFC 4918 §10.4.2: a tagged list is about the resource it names, not
     * about the request target. A server that held every condition against
     * the target would refuse a client that said something true about a
     * different resource.
     */
    public function testATaggedListIsHeldAgainstTheResourceItNames(): void
    {
        $asked = [];
        $header = IfHeader::parse(sprintf('<http://host/dav/calendars/work.ics> (<%s>)', self::TOKEN));

        $holds = IfEvaluator::holds($header, function (?string $resource) use (&$asked): ResourceState {
            $asked[] = $resource;

            return $this->state();
        });

        self::assertTrue($holds);
        self::assertSame(['http://host/dav/calendars/work.ics'], $asked);
    }

    /**
     * And an untagged list is about the request target, which is what a null
     * resource means to whoever resolves it.
     */
    public function testAnUntaggedListIsHeldAgainstTheRequestTarget(): void
    {
        $asked = [];

        IfEvaluator::holds(IfHeader::parse('(["abc"])'), function (?string $resource) use (&$asked): ResourceState {
            $asked[] = $resource;

            return $this->state();
        });

        self::assertSame([null], $asked);
    }

    /**
     * **Nothing is asked about a resource once an answer has been found.**
     * Every question here is a question to the lock storage and possibly to
     * the backend for an entity tag, and a header of ten lists should not be
     * ten round trips after the first one has already said yes.
     */
    public function testStopsAskingOnceAListHasHeld(): void
    {
        $asked = 0;
        $header = IfHeader::parse(sprintf('(<%s>) (["abc"]) (["abc"])', self::TOKEN));

        IfEvaluator::holds($header, function () use (&$asked): ResourceState {
            $asked++;

            return $this->state();
        });

        self::assertSame(1, $asked);
    }

    private function holds(string $header): bool
    {
        return IfEvaluator::holds(IfHeader::parse($header), fn (): ResourceState => $this->state());
    }

    /**
     * One resource, holding one lock and tagged `"abc"` — enough for a
     * condition to be true of it or false of it.
     */
    private function state(): ResourceState
    {
        return new ResourceState([self::TOKEN], ETag::parse('"abc"'));
    }
}
