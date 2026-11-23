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
use DavServices\Dav\Event\AfterCopy;
use DavServices\Dav\Event\AfterMove;
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
    /**
     * RFC 4918 §9.2, for a `PROPPATCH` that goes for one of these: „If
     * returning this error, the server SHOULD use the precondition code
     * 'cannot-modify-protected-property' inside the response body." The
     * element itself is §16's, and the status it goes with is `403`.
     */
    private const CANNOT_MODIFY_PROTECTED = '{DAV:}cannot-modify-protected-property';

    /**
     * @param list<string> $withheldFromEverything Names this plugin keeps out
     *                                             of an `allprop` answer, as `{namespace}localname`.
     *                                             Empty by default, because RFC 4918 §9.1 asks for
     *                                             "all custom properties" there and a deployment's
     *                                             own are exactly what that means — see
     *                                             {@see self::theNamesKeptAt()} for the ones that
     *                                             ask to be left out.
     * @param list<string> $protectedFromChange Names a client may not change,
     *                                          refused with RFC 4918 §9.2's
     *                                          `{DAV:}cannot-modify-protected-property`. Empty by
     *                                          default, because a dead property is the client's to
     *                                          write — see {@see self::keep()} for the ones a
     *                                          memo lets a deployment take back.
     */
    public function __construct(
        private readonly IPropertyStorageBackend $storage,
        private readonly array $withheldFromEverything = [],
        private readonly array $protectedFromChange = [],
    ) {
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
        $events->on(AfterCopy::class, $this->copy(...));
        $events->on(AfterMove::class, $this->carry(...));
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
     * of itself behind (R-DAV-05). A name refused below therefore takes the
     * rest of its request with it, which RFC 4918 §9.2 asks for in as many
     * words: "If one instruction fails, no instructions are executed."
     *
     * **And a name a deployment declared protected is refused here, because
     * nowhere else could.** A dead property is the client's to write, so the
     * list is empty by default. What fills it are the properties a memo lets
     * a deployment take back — and the memos say it conditionally, which
     * is why the names are not a constant of this library's:
     *
     * - RFC 6638 §9.1, of `CALDAV:schedule-calendar-transp`: "This
     *   property MAY be protected." A deployment that lets a client choose
     *   whether its calendar counts towards busy time is as conformant as one
     *   that does not.
     * - §9.2, of `CALDAV:schedule-default-calendar-URL`: "This property
     *   MAY be protected **in the case where a server does not support
     *   changing the default calendar**, or does not support a default
     *   calendar." Which is a condition only the deployment knows.
     *
     * A library that protected either by itself would answer a question the
     * memos put to somebody else.
     */
    public function keep(PropertiesChanging $event): void
    {
        if ($event->node() instanceof IProperties) {
            return;
        }

        $result = $event->result();
        $path = $result->path();

        foreach (array_keys($result->open()) as $name) {
            if (in_array($name, $this->protectedFromChange, true)) {
                $result->set($name, 403, self::CANNOT_MODIFY_PROTECTED);

                continue;
            }

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
     * Duplicates onto the copy what was kept for the original (RFC 4918
     * §9.8.2).
     */
    public function copy(AfterCopy $event): void
    {
        $this->storage->copyTo($event->from(), $event->to());
    }

    /**
     * Carries to the new path what was kept for the old one (R-PROP-04).
     */
    public function carry(AfterMove $event): void
    {
        $this->storage->moveTo($event->from(), $event->to());
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
            ? $this->theNamesKeptAt($path)
            : $result->stillWanted();

        $names = [];

        foreach ($candidates as $name) {
            if ($result->wants($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The names the store keeps here, less the ones a memo withholds from
     * `allprop`.
     *
     * **Only this can keep that sentence for a stored property.** RFC 4791
     * §5.2.3 to §5.2.9 and RFC 6638 §2.1.1, §2.2.1 and §9.1 each close with
     * "SHOULD NOT be returned by a PROPFIND DAV:allprop request". A plugin
     * answering such a property of its own keeps it by asking
     * {@see PropFindResult::wasNamed()}; one a **deployment** keeps is found
     * by its name in the store, and `allprop` is the one request that asks
     * the store what there is.
     *
     * **Which deployments those are is theirs to say**, so the list is a
     * parameter rather than a constant here. §9.1 is why: "This property
     * SHOULD be defined on all calendar collections" puts
     * `CALDAV:schedule-calendar-transp` on the deployment, and the plain way
     * to define a property is to store it. This plugin knows nothing of
     * CalDAV, and the names live where the memo does —
     * {@see \DavServices\Plugin\Scheduling::WITHHELD_FROM_ALLPROP}.
     *
     * **Named, it is answered all the same.** The sentence is about what
     * comes unasked, and a `prop` request never reaches here.
     *
     * @return list<string>
     */
    private function theNamesKeptAt(string $path): array
    {
        $names = [];

        foreach ($this->storage->propertyNames($path) as $name) {
            if (!in_array($name, $this->withheldFromEverything, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
