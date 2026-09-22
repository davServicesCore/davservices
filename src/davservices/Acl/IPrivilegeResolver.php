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

use DavServices\Exception\NotFound;

/**
 * Who may do what, here (RFC 3744 §5.4, R-PRIV-01 … R-PRIV-06).
 *
 * **This is the one seam through which an application's own knowledge reaches
 * the protocol.** Everything else in this library works out what a request
 * means; this says whether the person making it is allowed to. No other part
 * of it knows about sharing, ownership, delegation or whatever else a
 * deployment calls its rules — they all end up here, as a set of privileges
 * on a path.
 *
 * **`forPaths()` is not a convenience.** A `PROPFIND` with `Depth: 1` on a
 * collection of two hundred members asks about two hundred paths, and an
 * implementation that answers them one at a time turns one request into two
 * hundred queries. That is why the batch is part of the contract rather than
 * something an application might add: a seam that cannot be asked in bulk
 * cannot be made fast afterwards by anybody. An implementation **may** loop
 * over {@see self::forPath()} inside, and the contract tests will say so
 * (R-PRIV-01).
 *
 * **It answers rights and nothing else.** It is not asked to know whether a
 * path exists (R-PRIV-05), and in a batch it may not refuse one that does
 * not: a path nobody has heard of gets an empty set like any other
 * (R-PRIV-04). Existence is the tree's business, and a resolver that checked
 * it would be a second, slower tree.
 *
 * **Nobody in particular holds nothing** (R-PRIV-03): a null principal is
 * answered with an empty set, not with a guess and not with an error.
 *
 * **Asking twice gives the same answer** (R-PRIV-02). The resolution has no
 * side effects, and within one request it is memoised — see
 * {@see MemoizingPrivilegeResolver}, which any implementation may be wrapped
 * in rather than keeping its own cache.
 */
interface IPrivilegeResolver
{
    /**
     * What one principal holds on exactly one path.
     *
     * @param string|null $principalUri The principal, or null for a request
     *                                  from nobody in particular
     * @param string $path Normalised and decoded, without a leading slash
     *
     * @throws NotFound Only where the path is known not to exist; a resolver
     *                  is never obliged to find out (R-PRIV-05)
     *
     * @return PrivilegeSet Never null; an empty set means no access
     */
    public function forPath(?string $principalUri, string $path): PrivilegeSet;

    /**
     * The same question about several paths, in **one** call.
     *
     * Part of the contract rather than an optimisation: this is what keeps a
     * `PROPFIND` of two hundred members from becoming two hundred questions
     * (R-PRIV-01, R-BE-02).
     *
     * @param list<string> $paths
     *
     * @return array<string, PrivilegeSet> Keyed by path. **Every path asked
     *                                     about appears**, even where the
     *                                     set is empty and even where the
     *                                     path does not exist (R-PRIV-04):
     *                                     a caller that had to tell "no
     *                                     access" from "no answer" would
     *                                     have to ask again
     */
    public function forPaths(?string $principalUri, array $paths): array;

    /**
     * Which principals hold at least one privilege here.
     *
     * The other direction, and the one `DAV:acl` is written from: a client
     * asking who may see a calendar is asking this.
     *
     * **A key may be a principal URL or one of four names from RFC 3744
     * §5.5.1**, which gives six forms an entry's principal may take:
     *
     *     <!ELEMENT principal (href | all | authenticated | unauthenticated
     *      | property | self)>
     *
     * `{DAV:}all`, `{DAV:}authenticated`, `{DAV:}unauthenticated` and
     * `{DAV:}self` are empty elements, so a name says all there is to say,
     * and a URL never looks like one of them. A deployment whose rule is
     * „everybody may read" names `{DAV:}all` here — **and has to be able
     * to**, because §5.5 says this property is the list of entries, and one
     * that named nobody where everybody may read would not be incomplete but
     * untrue.
     *
     * The other two forms are not expressible: `DAV:property` carries a
     * property name inside it and `DAV:invert` wraps a whole principal, so
     * neither fits in a key. A deployment granting by those cannot report
     * them through this seam — said plainly, because the alternative is
     * finding out by reading somebody else's server.
     *
     * @return array<string, PrivilegeSet> Keyed by principal URI, or by one
     *                                     of the four names above
     */
    public function principalsForPath(string $path): array;
}
