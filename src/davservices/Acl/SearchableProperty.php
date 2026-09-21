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
 * One property principals may be searched by, and the sentence that explains
 * it (RFC 3744 §9.5).
 *
 * **A client cannot guess what a server will search.** §9.4 leaves the search
 * method — exact, prefix, substring, case-sensitive or not — to the server,
 * and says plainly that "for implementation efficiency, servers do not
 * typically support searching on all properties". A search over a property
 * this server does not search does not fail; it simply matches nobody. So
 * `DAV:principal-search-property-set` exists to be asked first, and this is
 * one line of its answer.
 *
 * It is shaped like {@see Privilege} and for the same reason: **the
 * description and its language are compulsory** in the DTD of §9.5, so the
 * text belongs to the property rather than to whoever writes the XML. An
 * extension that contributes a searchable property contributes the sentence
 * that explains it, or it contributes a hole in a required element.
 *
 * A list of these is a **list**: §9.5 asks a server to put the most
 * frequently searched first, so that a client with little room on screen
 * shows the ones people use without scrolling. The order is part of the
 * answer.
 */
final class SearchableProperty
{
    /**
     * @param string $name As `{namespace}localname`
     * @param string $description What this property holds, for a person to
     *                            read: it is what a client puts beside the
     *                            search box
     * @param string $language What language that description is in. §9.5
     *                         requires the attribute, so a server that always
     *                         wrote `en` would be labelling German text as
     *                         English — and a client believes the label
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $language = 'en',
    ) {
    }

    /**
     * What this library can answer for a principal, and nothing beyond it.
     *
     * `DAV:displayname` comes first because §9.4 names it as the one property
     * defined on every principal: "one expected use of this report is to
     * discover the URL of a principal associated with a given person or group
     * by searching for them by name".
     *
     * `DAV:alternate-URI-set` comes second because it is how somebody is
     * found by an address they are already known by — a `mailto:` a colleague
     * typed into a calendar invitation.
     *
     * A server that offered more would send clients searching for something
     * no principal has. One that wants more adds it:
     *
     *     $searchable = [
     *         ...SearchableProperty::standard(),
     *         new SearchableProperty(CalDav::USER_ADDRESS, 'an address this person is invited by'),
     *     ];
     *
     * @return list<self>
     */
    public static function standard(): array
    {
        return [
            new self('{DAV:}displayname', 'what a person is called'),
            new self('{DAV:}alternate-URI-set', 'another address this person is reached at'),
        ];
    }

    /**
     * The property name, as `{namespace}localname`.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * What this property holds, for a person to read (RFC 3744 §9.5).
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * What language that description is in, which §9.5 requires to be said.
     */
    public function language(): string
    {
        return $this->language;
    }
}
