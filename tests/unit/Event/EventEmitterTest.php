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

namespace DavServices\Tests\Unit\Event;

use Closure;
use DavServices\Event\Event;
use DavServices\Event\EventEmitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test list, derived from R-ARC-03 (an emitter with priorities, events that
 * are objects, and a chain a result object can end), R-ARC-05 (no static
 * state) and R-ARC-07 (more than one of these in one process).
 *
 * The chain is the whole point. A plugin that has answered a request says so
 * on the event, and nothing registered behind it runs — which is how the
 * default handling of a method is replaced rather than merely preceded.
 *
 * Order has to be settled twice over: by priority, and among equals by the
 * order they were registered in. Without the second, a server's behaviour
 * would depend on how PHP happened to sort two plugins that both asked for
 * the same priority.
 */
#[CoversClass(EventEmitter::class)]
#[CoversClass(Event::class)]
final class EventEmitterTest extends TestCase
{
    public function testHandsTheEventToTheListener(): void
    {
        $emitter = new EventEmitter();
        $seen = null;

        $emitter->on(Departure::class, static function (Departure $event) use (&$seen): void {
            $seen = $event;
        });

        $event = new Departure();

        self::assertSame($event, $emitter->emit($event));
        self::assertSame($event, $seen);
    }

    public function testAnEventWithoutListenersComesBackUntouched(): void
    {
        $event = (new EventEmitter())->emit(new Departure());

        self::assertFalse($event->isStopped());
        self::assertSame([], $event->trail);
    }

    /**
     * The lower number runs first, which reads backwards until you think of it
     * as a queue: a plugin that has to see a request before anything else asks
     * to be near the front.
     */
    public function testRunsTheListenersInTheOrderOfTheirPriority(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, self::note('late'), 200);
        $emitter->on(Departure::class, self::note('early'), 10);
        $emitter->on(Departure::class, self::note('middle'), 100);

        self::assertSame(['early', 'middle', 'late'], $emitter->emit(new Departure())->trail);
    }

    /**
     * Two plugins asking for the same priority is the ordinary case, not the
     * odd one. Deciding it by registration keeps a server's behaviour out of
     * the hands of PHP's sorting.
     */
    public function testKeepsTheOrderOfRegistrationAmongEqualPriorities(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, self::note('first'));
        $emitter->on(Departure::class, self::note('second'));
        $emitter->on(Departure::class, self::note('third'));

        self::assertSame(['first', 'second', 'third'], $emitter->emit(new Departure())->trail);
    }

    public function testUsesTheSamePriorityForEveryListenerThatNamesNone(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, self::note('explicit'), 100);
        $emitter->on(Departure::class, self::note('default'));

        self::assertSame(['explicit', 'default'], $emitter->emit(new Departure())->trail);
    }

    /**
     * The event is the result object of R-ARC-03: a listener that has dealt
     * with the matter says so on it, and the chain ends there.
     */
    public function testAStoppedEventEndsTheChain(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, self::note('first'));
        $emitter->on(Departure::class, static function (Departure $event): void {
            $event->trail[] = 'stops here';
            $event->stop();
        });
        $emitter->on(Departure::class, self::note('never runs'));

        $event = $emitter->emit(new Departure());

        self::assertSame(['first', 'stops here'], $event->trail);
        self::assertTrue($event->isStopped());
    }

    public function testAnEventStoppedByTheVeryFirstListenerReachesNoOther(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, static function (Departure $event): void {
            $event->stop();
        });
        $emitter->on(Departure::class, self::note('never runs'));

        self::assertSame([], $emitter->emit(new Departure())->trail);
    }

    /**
     * An event that arrives already stopped is not passed on at all — which is
     * what lets one emitter hand an event to another without the second one
     * undoing the first's decision.
     */
    public function testAnEventThatArrivesStoppedReachesNoListener(): void
    {
        $emitter = new EventEmitter();
        $emitter->on(Departure::class, self::note('never runs'));

        $event = new Departure();
        $event->stop();

        self::assertSame([], $emitter->emit($event)->trail);
    }

    /**
     * Listeners are kept per event class. Without that, every plugin would be
     * woken for every event and would have to work out whether it cares.
     */
    public function testAListenerHearsOnlyItsOwnKindOfEvent(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, self::note('departure'));
        $emitter->on(Arrival::class, static function (Arrival $event): void {
            $event->trail[] = 'arrival';
        });

        self::assertSame(['departure'], $emitter->emit(new Departure())->trail);
        self::assertSame(['arrival'], $emitter->emit(new Arrival())->trail);
    }

    public function testAListenerRegisteredTwiceRunsTwice(): void
    {
        $emitter = new EventEmitter();
        $listener = self::note('twice');

        $emitter->on(Departure::class, $listener);
        $emitter->on(Departure::class, $listener);

        self::assertSame(['twice', 'twice'], $emitter->emit(new Departure())->trail);
    }

    /**
     * R-ARC-05 and R-ARC-07: no static state, so the library can be built more
     * than once in one process — which every test in this suite relies on, and
     * every embedding application that serves two mounts from one request.
     */
    public function testTwoEmittersKnowNothingOfEachOther(): void
    {
        $one = new EventEmitter();
        $other = new EventEmitter();

        $one->on(Departure::class, self::note('one'));
        $other->on(Departure::class, self::note('other'));

        self::assertSame(['one'], $one->emit(new Departure())->trail);
        self::assertSame(['other'], $other->emit(new Departure())->trail);
    }

    /**
     * A listener that throws has found something the server has to answer for.
     * Swallowing it here would turn a failed write into a silent success.
     */
    public function testAnExceptionFromAListenerIsPassedOnAndEndsTheChain(): void
    {
        $emitter = new EventEmitter();

        $emitter->on(Departure::class, static function (): void {
            throw new RuntimeException('the backend is gone');
        });
        $emitter->on(Departure::class, self::note('never runs'));

        $event = new Departure();

        try {
            $emitter->emit($event);
        } catch (RuntimeException $thrown) {
            self::assertSame('the backend is gone', $thrown->getMessage());
            self::assertSame([], $event->trail);

            return;
        }

        self::fail('The exception did not leave the emitter.');
    }

    /**
     * @return Closure(Departure): void
     */
    private static function note(string $mark): callable
    {
        return static function (Departure $event) use ($mark): void {
            $event->trail[] = $mark;
        };
    }
}

/**
 * An event to listen for, with somewhere for a listener to leave a mark.
 */
final class Departure extends Event
{
    /** @var list<string> */
    public array $trail = [];
}

/**
 * A second kind, so that the emitter has something to tell them apart by.
 */
final class Arrival extends Event
{
    /** @var list<string> */
    public array $trail = [];
}
