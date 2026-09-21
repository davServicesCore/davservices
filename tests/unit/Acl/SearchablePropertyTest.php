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

namespace DavServices\Tests\Unit\Acl;

use DavServices\Acl\SearchableProperty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §9.5 and §9.4.
 *
 * **One property a client may search principals by, and the sentence that
 * explains it.** RFC 3744 §9.5 has a server say which properties can be
 * searched at all, because §9.4 leaves the search method to the server and
 * "for implementation efficiency, servers do not typically support searching
 * on all properties". A client that could not ask would have to guess, and a
 * guess that is wrong matches nothing at all.
 *
 * It is shaped like {@see \DavServices\Acl\Privilege} and for the same
 * reason: **the DTD makes the description and its language compulsory**, so
 * the text belongs to the property rather than to whoever writes the XML. An
 * extension that contributes a searchable property contributes the sentence
 * that explains it, or it contributes a hole in a required element.
 *
 * The **order** of a list of these is meaningful — §9.5 asks a server to put
 * the most frequently searched first, so that a client with little room on
 * screen shows the useful ones — which is why this is a list and not a set.
 */
#[CoversClass(SearchableProperty::class)]
final class SearchablePropertyTest extends TestCase
{
    private const CALENDAR_USER_ADDRESS = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    public function testIsKnownByItsName(): void
    {
        self::assertSame(
            '{DAV:}displayname',
            (new SearchableProperty('{DAV:}displayname', 'what a person is called'))->name(),
        );
    }

    /**
     * §9.5: the description is "a human-readable description of what
     * information this property represents" — it is what a client puts beside
     * the search box, so a property without one is a search box nobody can
     * label.
     */
    public function testCarriesTheDescriptionAPersonReads(): void
    {
        $property = new SearchableProperty('{DAV:}displayname', 'what a person is called');

        self::assertSame('what a person is called', $property->description());
    }

    /**
     * **§9.5 requires the language to be said**, and a server that always
     * wrote `en` would be labelling German text as English — which is worse
     * than saying nothing, because a client believes it.
     */
    public function testSaysWhatLanguageThatDescriptionIsIn(): void
    {
        $english = new SearchableProperty('{DAV:}displayname', 'what a person is called');
        $german = new SearchableProperty('{DAV:}displayname', 'wie jemand heißt', 'de');

        self::assertSame('en', $english->language(), 'English unless somebody says otherwise');
        self::assertSame('de', $german->language());
    }

    /**
     * **The standard list is what this library can answer for a principal**,
     * and nothing beyond it: a server that offered a property no principal
     * has would send clients searching for something that never matches.
     *
     * `DAV:displayname` comes first because §9.4 names it as the one defined
     * on all principals — "one expected use of this report is to discover the
     * URL of a principal associated with a given person ... by searching over
     * DAV:displayname".
     */
    public function testTheStandardOnesAreWhatAPrincipalActuallyHas(): void
    {
        self::assertSame(
            ['{DAV:}displayname', '{DAV:}alternate-URI-set'],
            self::namesOf(SearchableProperty::standard()),
        );
    }

    /**
     * And every one of them brings its sentence, in a language it names —
     * because the report writes them straight out, and an empty description
     * is a `DAV:principal-search-property` that does not satisfy its own DTD.
     */
    public function testEveryStandardOneExplainsItself(): void
    {
        foreach (SearchableProperty::standard() as $property) {
            self::assertNotSame('', $property->description(), $property->name());
            self::assertNotSame('', $property->language(), $property->name());
        }
    }

    /**
     * **A list that could not grow would be the wrong list.** The property
     * CalDAV clients actually search by is
     * `CALDAV:calendar-user-address-set` (RFC 6638 §2.4.1), and CalDAV is a
     * layer above this one — so an application adds to the standard list
     * rather than replacing it.
     */
    public function testAnApplicationAddsItsOwn(): void
    {
        $properties = [
            ...SearchableProperty::standard(),
            new SearchableProperty(self::CALENDAR_USER_ADDRESS, 'an address this person is invited by'),
        ];

        self::assertSame(
            ['{DAV:}displayname', '{DAV:}alternate-URI-set', self::CALENDAR_USER_ADDRESS],
            self::namesOf($properties),
            'the new one is there and the standard ones keep their order',
        );
    }

    /**
     * @param list<SearchableProperty> $properties
     *
     * @return list<string>
     */
    private static function namesOf(array $properties): array
    {
        return array_map(static fn (SearchableProperty $property): string => $property->name(), $properties);
    }
}
