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

namespace DavServices\Acl;

/**
 * A node that defines privileges of its own.
 *
 * RFC 3744 §5.3 opens with what `DAV:supported-privilege-set` is:
 *
 * > This is a protected property that identifies the privileges defined for
 * > the resource.
 *
 * **For the resource** — so the tree is not the server's one answer repeated.
 * Most resources have nothing to add and this interface is for the ones that
 * do: RFC 6638 §6 requires its eight scheduling privileges to "appear in the
 * DAV:supported-privilege-set property of scheduling Outbox and Inbox
 * collections on which they are defined", and each of §6.1 and §6.2 adds that
 * its four "are ignored if applied to a resource other than" that kind of
 * collection. A privilege the server would disregard is not defined for that
 * resource, and listing it there would mislead the one reader §5.3 names: an
 * administrative client that "would list the supported privileges in a dialog
 * box, and allow the user to choose non-abstract privileges to apply in an
 * ACE".
 *
 * It is the node's answer and not the plugin's for the reason
 * {@see Privilege} gives of `CALDAV:read-free-busy`: access control is a layer
 * **below** CalDAV, so {@see \DavServices\Plugin\Acl} cannot name a
 * scheduling collection. It asks instead, the same way `DAV:resourcetype` is
 * asked of {@see \DavServices\Dav\IResourceType}.
 *
 * **What comes back is the whole tree, not the addition.** RFC 6638 §6.3
 * prescribes where its two aggregates hang — "DAV:all contains
 * CALDAV:schedule-deliver and CALDAV:schedule-send" — and only the branch
 * itself knows under which privilege it belongs. A node that returned its own
 * in isolation would leave whoever asked to guess that, and a node that
 * forgot what it was given would strike `DAV:read` off its own report.
 */
interface IPrivileges
{
    /**
     * The privileges defined for this resource.
     *
     * @param Privilege $everywhere What this server grants everywhere, to
     *                              hang the node's own into — see
     *                              {@see Privilege::with()}, which leaves the
     *                              tree it was given alone
     */
    public function privilegesDefinedHere(Privilege $everywhere): Privilege;
}
