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

namespace DavServices\CalDav;

/**
 * A calendar home that can take a shared calendar into itself (R-CAL-20).
 *
 * §5.4.1 of `caldav-sharing` puts the sharee's reply "to the sharee's calendar
 * home collection resource", and says what an acceptance leaves behind: "the
 * URI of the shared calendar in the sharee's calendar home **created by the
 * invite acceptance**".
 *
 * **Only an application can create it.** A sharee's copy is a view on somebody
 * else's store — a wrapper, a second mapping of the same rows, a database view
 * — and a library that holds no data has nothing to make it out of. So this is
 * the seam, and it is here rather than in `Backend\` for the reason
 * {@see IShareTarget} gives: what a node does to itself is an interface in
 * this namespace.
 *
 * ## Declining needs nothing
 *
 * There is no method for it, because a refusal creates nothing: §5.4.1 owes a
 * body only to "a POST request that accepts", and all a refusal changes is the
 * sharee's standing on the sharer's list. A home that cannot take a shared
 * calendar can therefore still decline one, which is why the two are not one
 * method with a flag.
 *
 * ## And taking one away is a `DELETE`
 *
 * §5.4.2 gives that its own shape — "a DELETE request is targeted at the
 * shared calendar URI" — so it is the copy that goes, through the ordinary
 * method, and nothing here is asked about it.
 */
interface IShareeHome
{
    /**
     * Takes the calendar at this URL into this home, and says what it called
     * the copy.
     *
     * The name is a member name of this collection, which
     * {@see \DavServices\Plugin\Sharing} turns into the URI §5.4.1 answers
     * with. **Choosing it is the store's**, because it has to be a name the
     * store can address, and it is handed back rather than assumed for the
     * same reason {@see \DavServices\Dav\ICollection::createFile()} hands back
     * an entity tag: only the thing that did it knows what it did.
     *
     * @param string $hostUrl The sharer's own calendar, as §6.17 named it in
     *                        the invitation — the value the copy answers
     *                        §5.2.4's `CS:shared-url` with
     *
     * @return string The name of the copy inside this home
     */
    public function acceptShareOf(string $hostUrl): string;
}
