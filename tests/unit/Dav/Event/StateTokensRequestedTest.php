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

use DavServices\Dav\Event\StateTokensRequested;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §10.4 and R-ARC-02.
 *
 * **The seam that lets the core evaluate an `If` header without knowing what
 * a lock is.** A state token is whatever a plugin says a resource is in the
 * state of; today only the lock plugin answers, and tomorrow anything else
 * that hands out tokens may.
 *
 * That is the whole reason this is an event rather than a call into a
 * backend: a server built without the lock plugin still has to hold a client
 * to the conditions it set itself, and it has to do that without a single
 * line about locking in it.
 */
#[CoversClass(StateTokensRequested::class)]
final class StateTokensRequestedTest extends TestCase
{
    public function testKnowsWhichPathIsBeingAskedAbout(): void
    {
        self::assertSame('calendars/work.ics', (new StateTokensRequested('calendars/work.ics'))->path());
    }

    /**
     * A resource nobody has said anything about is in no state anybody named,
     * which is what a server without plugins answers to every question.
     */
    public function testKnowsOfNoTokensUntilSomebodyNamesOne(): void
    {
        self::assertSame([], (new StateTokensRequested('calendars/work.ics'))->tokens());
    }

    public function testTakesTheTokensAListenerNames(): void
    {
        $event = new StateTokensRequested('calendars/work.ics');

        $event->add('opaquelocktoken:one');
        $event->add('opaquelocktoken:other', 'opaquelocktoken:third');

        self::assertSame(
            ['opaquelocktoken:one', 'opaquelocktoken:other', 'opaquelocktoken:third'],
            $event->tokens(),
        );
    }

    /**
     * **A token named twice is one token.** Two listeners may know of the
     * same hold — a lock plugin and something that mirrors it — and a
     * condition is satisfied by a token being there, not by how often.
     */
    public function testNamesEachTokenOnce(): void
    {
        $event = new StateTokensRequested('calendars/work.ics');

        $event->add('opaquelocktoken:one');
        $event->add('opaquelocktoken:one');

        self::assertSame(['opaquelocktoken:one'], $event->tokens());
    }
}
