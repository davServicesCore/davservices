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

namespace DavServices\Backend;

use DavServices\Xml\Element;

/**
 * Where the dead properties of RFC 4918 §3 are kept (R-PROP-02).
 *
 * A dead property is one the server does not understand and keeps anyway: a
 * calendar's colour, a client's own bookkeeping, whatever somebody invented
 * last year. The server's only duty towards it is to hand it back exactly as
 * it arrived, and this is where that duty is discharged.
 *
 * Keyed by the path inside the tree, not by the node: a node is an object that
 * lives for one request, and what is kept here outlives it. That is also why
 * a path that goes away, or moves, has to be said so — the storage cannot see
 * it happen.
 *
 * **A path that merely begins the same way is a different path.** `alice2`
 * does not lie below `alice`, and an implementation that compares prefixes
 * without the slash between them deletes somebody else's properties.
 */
interface IPropertyStorageBackend
{
    /**
     * The names of the properties kept for a path.
     *
     * What `DAV:propname` is answered with, and the only way a dead property
     * nobody has asked for by name can be found at all.
     *
     * @return list<string> Names as `{namespace}localname`
     */
    public function propertyNames(string $path): array;

    /**
     * The values kept for a path, of the properties asked for.
     *
     * @param list<string> $names Names as `{namespace}localname`
     *
     * @return array<string, Element|string|null> Keyed by name; one that is
     *                                            not kept is left out rather
     *                                            than given as null, because
     *                                            null is a value a property
     *                                            may hold
     */
    public function properties(string $path, array $names): array;

    /**
     * Changes what is kept for a path.
     *
     * All of them or none: a `PROPPATCH` is atomic (R-DAV-05), and by the time
     * this is called nothing is left that could refuse. A value of null
     * removes the property, and removing one that was never there is no error
     * — that is a client being out of date, not a fault.
     *
     * @param array<string, Element|string|null> $mutations Keyed by name
     */
    public function patchProperties(string $path, array $mutations): void;

    /**
     * Drops everything kept for a path and for everything below it.
     *
     * R-PROP-04: what a `DELETE` removed keeps no properties behind it. They
     * would otherwise reappear on the next resource of that name, which is the
     * sort of surprise nobody has a way of explaining.
     */
    public function forget(string $path): void;

    /**
     * Copies everything kept for a path, and for everything below it, to a
     * second path, leaving the first as it is.
     *
     * RFC 4918 §9.8.2: a `COPY` duplicates the dead properties of the source.
     * A copy that arrived without the colour and the name of what it was
     * copied from is not a copy in any sense a client would recognise.
     */
    public function copyTo(string $from, string $to): void;

    /**
     * Carries everything kept for a path, and for everything below it, to a
     * new path.
     *
     * R-PROP-04 again: a calendar that arrived at its new address without its
     * colour and its name would look to a client like a different calendar.
     */
    public function moveTo(string $from, string $to): void;
}
