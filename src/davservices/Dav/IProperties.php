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

namespace DavServices\Dav;

use DavServices\Xml\Element;

/**
 * A node that keeps properties of its own.
 *
 * Live properties are computed by the server and by plugins; these are the
 * ones the node itself stores — the dead properties of RFC 4918 §3, and any
 * live one a backend can answer better than the server can.
 */
interface IProperties
{
    /**
     * The names of the properties this node keeps.
     *
     * What `DAV:propname` is answered with (RFC 4918 §9.1), and the only way a
     * server can learn of a property it was never asked for by name — which
     * is every dead property under `DAV:allprop`.
     *
     * Working out the values is no part of it. A node that has to fetch them
     * to answer this has misread the question: clients ask `propname`
     * precisely to avoid the expensive one.
     *
     * @return list<string> Property names as `{namespace}localname`
     */
    public function propertyNames(): array;

    /**
     * The values the node has for these properties.
     *
     * @param list<string> $names Property names as `{namespace}localname`
     *
     * @return array<string, Element|string|null> Keyed by name, the value as
     *                                            text or as the XML it holds
     *                                            (R-PROP-03); a property the
     *                                            node does not have is left
     *                                            out rather than given as
     *                                            null, because null is a
     *                                            value a property may hold
     */
    public function properties(array $names): array;

    /**
     * Changes properties, all of them or none.
     *
     * RFC 4918 §9.2 makes `PROPPATCH` atomic: if one change cannot be made,
     * nothing is changed and the others are reported as `424`.
     *
     * @param array<string, Element|string|null> $mutations Keyed by name;
     *                                                      null removes the
     *                                                      property
     *
     * @return array<string, int> The status for each name, whether it was
     *                            changed, refused, or failed for another's sake
     */
    public function patchProperties(array $mutations): array;
}
