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

namespace DavServices\Plugin;

use DavServices\Backend\IPropertyStorageBackend;
use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\IProperties;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Event\EventEmitter;
use DavServices\Xml\Element;

/**
 * Keeps the properties the server does not understand (RFC 4918 §3).
 *
 * A dead property is one a client invented and the server stores anyway: a
 * calendar's colour, a client's own bookkeeping, whatever somebody thought of
 * last year. Without somewhere to put them, every client that relies on one
 * finds its settings gone at the next start.
 *
 * The whole plugin is three listeners on seams that were already there:
 *
 *     $properties = new DeadProperties(new PropertyStorage('/var/lib/davservices'));
 *     $properties->registerOn($server->events());
 *
 * Nothing in `PROPFIND`, `PROPPATCH` or `DELETE` knows that dead properties
 * exist, and an application that wants none of them registers none. Where they
 * are kept is the backend's business (R-PROP-02), and what they hold is any
 * XML at all (R-PROP-03).
 */
final class DeadProperties
{
    public function __construct(private readonly IPropertyStorageBackend $storage)
    {
    }

    /**
     * Listens where it has to. A plugin knows which seams it needs; an
     * application should not have to.
     */
    public function registerOn(EventEmitter $events): void
    {
        $events->on(PropertiesRequested::class, $this->answer(...));
        $events->on(PropertiesChanging::class, $this->keep(...));
        $events->on(AfterUnbind::class, $this->forget(...));
    }

    /**
     * Answers a `PROPFIND` with what is kept for the path.
     */
    public function answer(PropertiesRequested $event): void
    {
        $result = $event->result();
        $path = $result->path();

        if ($result->form() === PropFindForm::NamesOnly) {
            // The values are dropped from the answer, so they are not fetched.
            foreach ($this->storage->propertyNames($path) as $name) {
                $result->set($name, null);
            }

            return;
        }

        foreach ($this->storage->properties($path, $this->namesToAnswer($result, $path)) as $name => $value) {
            $result->set($name, $value);
        }
    }

    /**
     * Takes on the changes of a `PROPPATCH` for a node that keeps none itself.
     *
     * **A node that implements {@see IProperties} keeps its own**, and is left
     * to. That is what the interface means: the properties the node itself
     * stores, dead ones included. A plugin that took them out of its hands
     * would be storing them in a second place, and would take the node's
     * chance to refuse a change along with them.
     *
     * Nothing is written here: the writers run once the whole request has been
     * agreed to, which is what keeps a refused `PROPPATCH` from leaving half
     * of itself behind (R-DAV-05).
     */
    public function keep(PropertiesChanging $event): void
    {
        if ($event->node() instanceof IProperties) {
            return;
        }

        $result = $event->result();
        $path = $result->path();

        foreach (array_keys($result->open()) as $name) {
            $result->willWrite($name, function (Element|string|null $value) use ($path, $name): void {
                $this->storage->patchProperties($path, [$name => $value]);
            });
        }
    }

    /**
     * Drops what was kept for a path that has gone (R-PROP-04).
     */
    public function forget(AfterUnbind $event): void
    {
        $this->storage->forget($event->path());
    }

    /**
     * Which names are worth fetching values for.
     *
     * Under `allprop` the storage's own names are the question — a dead
     * property nobody has named can be found in no other way — and under
     * `prop` they are the names still open. Either way, one that somebody has
     * answered already is left alone.
     *
     * @return list<string>
     */
    private function namesToAnswer(PropFindResult $result, string $path): array
    {
        $candidates = $result->form() === PropFindForm::Everything
            ? $this->storage->propertyNames($path)
            : $result->stillWanted();

        $names = [];

        foreach ($candidates as $name) {
            if ($result->wants($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
