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

use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Exception\Forbidden;

/**
 * Where the principals of RFC 3744 come from.
 *
 * **This is the seam to an application's own idea of who exists.** Almost
 * nobody keeps their users in a WebDAV server: they are in a directory, a
 * database, an identity provider. A backend is what turns that into
 * principals, and it is the only thing the access control in this library
 * asks about people.
 *
 * Two questions, and they are not the same one. **One by name** is what every
 * request needs: a path names a principal, and the server has to know whether
 * it exists. **All of them** is what a listing needs, and a deployment with a
 * hundred thousand users may well refuse it — `children()` on a collection
 * already says a listing may be refused, and this says the same thing one
 * layer down.
 */
interface IPrincipalBackend
{
    /**
     * One principal by the name it is known by, or null where there is none.
     *
     * @param string $name The member name inside the principal collection
     */
    public function principal(string $name): ?PrincipalInfo;

    /**
     * Every principal there is.
     *
     * @throws Forbidden If this backend will not be listed — which a large
     *                   deployment may well decide, and which is a better
     *                   answer than a listing nobody can use
     *
     * @return list<PrincipalInfo>
     */
    public function principals(): array;
}
