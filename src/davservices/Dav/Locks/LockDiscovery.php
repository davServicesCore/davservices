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

namespace DavServices\Dav\Locks;

use DateTimeImmutable;
use DavServices\Xml\Element;

/**
 * What a client is told about the locks on a resource (RFC 4918 §14.1).
 *
 * **Written once, because it is read in two places.** A `LOCK` request is
 * answered with this element, and so is the `DAV:lockdiscovery` property of a
 * `PROPFIND`. Two spellings of one thing would be two chances to disagree,
 * and a client that read a lock one way and refreshed it the other would be
 * refreshing something else.
 *
 * Three parts of it are easy to get subtly wrong, and each costs a client
 * something real. The **timeout is what is left**, counted from now, not what
 * was asked for — a server that echoed the original hour would have its
 * clients refresh too late. The **token is an href**, because that is where a
 * client looks for what it must put in its `If` and `Lock-Token` headers. And
 * the **root is where the lock was taken**, not where the question was asked:
 * a deep lock answers for every path inside it, and the collection is the
 * resource a client would have to unlock.
 */
final class LockDiscovery
{
    /**
     * The `DAV:lockdiscovery` element, empty where nothing is held.
     *
     * @param list<LockInfo> $locks
     * @param callable(string): string $href Where the server is mounted is
     *                                       the server's own business, so it
     *                                       is handed in rather than guessed
     */
    public static function element(array $locks, callable $href, DateTimeImmutable $now): Element
    {
        $discovery = new Element('{DAV:}lockdiscovery');

        foreach ($locks as $lock) {
            $discovery->append(self::activeLock($lock, $href, $now));
        }

        return $discovery;
    }

    /**
     * The same, wrapped in the `DAV:prop` that RFC 4918 §9.10.1 asks a `LOCK`
     * to be answered with.
     *
     * @param list<LockInfo> $locks
     * @param callable(string): string $href
     */
    public static function asProperty(array $locks, callable $href, DateTimeImmutable $now): Element
    {
        $property = new Element('{DAV:}prop');

        $property->append(self::element($locks, $href, $now));

        return $property;
    }

    /**
     * @param callable(string): string $href
     */
    private static function activeLock(LockInfo $lock, callable $href, DateTimeImmutable $now): Element
    {
        $active = new Element('{DAV:}activelock');

        $active->append(self::holding('{DAV:}lockscope', $lock->scope() === LockScope::Shared ? '{DAV:}shared' : '{DAV:}exclusive'));
        $active->append(self::holding('{DAV:}locktype', '{DAV:}write'));
        $active->append(self::saying('{DAV:}depth', $lock->isDeep() ? 'infinity' : '0'));

        self::appendOwner($active, $lock->owner());

        $active->append(self::saying('{DAV:}timeout', self::timeoutOf($lock, $now)));
        $active->append(self::holdingHref('{DAV:}locktoken', $lock->token()));
        $active->append(self::holdingHref('{DAV:}lockroot', $href($lock->root())));

        return $active;
    }

    /**
     * RFC 4918 §10.7: the seconds that are left, counted from now.
     *
     * Never fewer than none. A lock whose moment has arrived has nothing left
     * in it, and a negative number would have a client work out a refresh
     * time in the past.
     */
    private static function timeoutOf(LockInfo $lock, DateTimeImmutable $now): string
    {
        $expires = $lock->expiresAt();

        if ($expires === null) {
            return 'Infinite';
        }

        return sprintf('Second-%d', max(0, $expires->getTimestamp() - $now->getTimestamp()));
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client sent and comes back
     * as it went in, and a lock nobody claimed carries no owner at all —
     * an empty element would say that somebody sent nothing, which is a
     * different thing from not having been asked.
     */
    private static function appendOwner(Element $active, Element|string|null $owner): void
    {
        if ($owner === null) {
            return;
        }

        $held = new Element('{DAV:}owner');

        $owner instanceof Element ? $held->append($owner) : $held->appendText($owner);

        $active->append($held);
    }

    private static function holding(string $name, string $child): Element
    {
        $element = new Element($name);

        $element->append(new Element($child));

        return $element;
    }

    private static function holdingHref(string $name, string $href): Element
    {
        $element = new Element($name);

        $element->append(self::saying('{DAV:}href', $href));

        return $element;
    }

    private static function saying(string $name, string $text): Element
    {
        $element = new Element($name);

        $element->appendText($text);

        return $element;
    }
}
