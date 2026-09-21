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

use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test list, derived from RFC 5397, RFC 3744 §5.4 and R-PRIV-03.
 *
 * **One question, one place to answer it.** Who is signed in is needed by
 * `current-user-principal`, by `current-user-privilege-set` and by every
 * access check — and if each kept its own idea of it, they could disagree.
 * A server whose two answers disagree about identity shows one person's
 * calendar under another person's name.
 *
 * **Nobody answering is a proper answer**, not a missing one: a request from
 * somebody who has not signed in holds nothing (R-PRIV-03).
 *
 * **Two listeners naming different principals is a mistake in the wiring**,
 * and it is said out loud. Settling it by registration order would decide by
 * accident the one thing that must never be decided by accident.
 */
#[CoversClass(CurrentPrincipalRequested::class)]
final class CurrentPrincipalRequestedTest extends TestCase
{
    public function testKnowsTheRequestItIsAbout(): void
    {
        $request = new Request('PROPFIND', '/calendars/work.ics');

        self::assertSame($request, (new CurrentPrincipalRequested($request))->request());
    }

    /**
     * R-PRIV-03: with nobody having said, nobody is signed in.
     */
    public function testKnowsOfNobodyUntilSomebodySays(): void
    {
        self::assertNull($this->event()->principal());
    }

    public function testTakesTheAnswerAListenerGives(): void
    {
        $event = $this->event();

        $event->answerWith('principals/alice');

        self::assertSame('principals/alice', $event->principal());
    }

    /**
     * Two listeners that agree are no conflict — an application whose
     * fallback names the same principal as its authentication is simply
     * saying the same thing twice.
     */
    public function testTwoListenersMaySayTheSameThing(): void
    {
        $event = $this->event();

        $event->answerWith('principals/alice');
        $event->answerWith('principals/alice');

        self::assertSame('principals/alice', $event->principal());
    }

    /**
     * **And two that disagree are a mistake in the wiring.** Of all the
     * things to settle by accident of registration order, who somebody is
     * would be the worst: the server would grant one person's privileges
     * under another person's name and look right doing it.
     */
    public function testTwoListenersThatDisagreeAreAMistake(): void
    {
        $event = $this->event();

        $event->answerWith('principals/alice');

        $this->expectException(RuntimeException::class);

        $event->answerWith('principals/bob');
    }

    private function event(): CurrentPrincipalRequested
    {
        return new CurrentPrincipalRequested(new Request('PROPFIND', '/calendars/work.ics'));
    }
}
