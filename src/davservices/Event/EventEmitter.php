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

namespace DavServices\Event;

use Closure;

/**
 * Where plugins take part in what the server does.
 *
 * A listener is registered for the class of event it wants, with a priority:
 * the lower the number, the earlier it runs. Listeners that name the same
 * priority run in the order they were registered, because two plugins asking
 * for the same place is the ordinary case, and a server whose behaviour
 * depended on how PHP happened to sort them would be a server nobody could
 * reason about.
 *
 * The chain ends as soon as a listener stops the event. That is how the
 * default handling of a method is replaced rather than merely preceded: the
 * plugin that answered says so, and the server's own handler never runs.
 *
 * There is no static state anywhere in here (R-ARC-05), so a process may hold
 * as many of these as it likes (R-ARC-07).
 */
final class EventEmitter
{
    /** What a listener gets when it names no priority of its own. */
    public const DEFAULT_PRIORITY = 100;

    /**
     * Listeners by event class, each already in the order they will run in.
     *
     * @var array<class-string<IEvent>, list<array{priority: int, listener: Closure}>>
     */
    private array $listeners = [];

    /**
     * Registers a listener for one kind of event.
     *
     * @template TEvent of IEvent
     *
     * A class that is no event of this library is refused by the static
     * analyser rather than at run time (R-QS-08 requires one). A check here
     * could never fire for a caller who runs it, and a second guard for the
     * same thing is a second place to keep right.
     *
     * @param class-string<TEvent> $event The class the listener is about
     * @param Closure(TEvent): void $listener What is to happen
     * @param int $priority Lower runs earlier
     */
    public function on(string $event, Closure $listener, int $priority = self::DEFAULT_PRIORITY): void
    {
        $this->listeners[$event][] = ['priority' => $priority, 'listener' => $listener];

        // PHP 8 sorts stably, which is what keeps equal priorities in the
        // order they were registered in.
        usort(
            $this->listeners[$event],
            static fn (array $one, array $other): int => $one['priority'] <=> $other['priority'],
        );
    }

    /**
     * Hands the event to every listener for its class, in order, until one of
     * them stops it.
     *
     * @template TEvent of IEvent
     *
     * @param TEvent $event
     *
     * @return TEvent The same event, for the caller to read the outcome off
     */
    public function emit(IEvent $event): IEvent
    {
        foreach ($this->listeners[$event::class] ?? [] as $registered) {
            if ($event->isStopped()) {
                break;
            }

            $registered['listener']($event);
        }

        return $event;
    }
}
