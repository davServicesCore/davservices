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

namespace DavServices\Xml;

/**
 * The `DAV:error` body of RFC 4918 §16, built in one place.
 *
 * A refusal says which rule it broke, and §16 has one shape for saying it:
 * a `DAV:error` holding the precondition or postcondition element. Two
 * places in this library write one — the server, for a request that failed
 * whole, and {@see MultiStatus}, for one that failed per resource — and a
 * second copy of the shape is a second chance to get it wrong.
 *
 * **A condition may be a name or a whole element.** Most are empty and a
 * name is all there is to say: `DAV:propfind-finite-depth` means only
 * itself. Some carry their own detail — RFC 3744 §7.1.1's
 * `DAV:need-privileges` names the resource that lacked a privilege and the
 * privilege it lacked — and a refusal that could only name a condition would
 * leave a client knowing it may not, and nothing about what to change.
 */
final class Error
{
    /**
     * The body for one condition.
     *
     * @param Element|string $condition The condition element, or its name as
     *                                  `{namespace}localname` where it has
     *                                  nothing inside it
     */
    public static function of(Element|string $condition): Element
    {
        $error = new Element('{DAV:}error');

        $error->append($condition instanceof Element ? $condition : new Element($condition));

        return $error;
    }
}
