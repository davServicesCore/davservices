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

namespace DavServices\Tests\Unit\CardDav;

use DavServices\CardDav\PropertyName;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for how a client names a vCard property. R-CARD-03, R-CARD-05.
 *
 * **RFC 6352 states the rule twice, word for word**, once for the filter and
 * once for the data a report returns (§10.5.1 and §10.4.2):
 *
 * > vCard allows a "group" prefix to appear before a property name in the vCard
 * > data. When the "name" attribute does not specify a group prefix, it MUST
 * > match properties in the vCard data without a group prefix or with any group
 * > prefix. When the "name" attribute includes a group prefix, it MUST match
 * > properties that have exactly the same group prefix and name. For example, a
 * > "name" set to "TEL" will match "TEL", "X-ABC.TEL", "X-ABC-1.TEL" vCard
 * > properties. A "name" set to "X-ABC.TEL" will match an "X-ABC.TEL" vCard
 * > property only, it will not match "TEL" or "X-ABC-1.TEL".
 *
 * Written twice in the memo and therefore once here: two copies would be two
 * chances for a `prop-filter` and an `address-data` to disagree about which
 * properties a client meant.
 */
#[CoversClass(PropertyName::class)]
final class PropertyNameTest extends TestCase
{
    /**
     * The memo's own first example: no prefix matches whatever prefix there is.
     */
    public function testANameWithoutAPrefixMatchesAnyPrefix(): void
    {
        $name = PropertyName::of('TEL');

        self::assertTrue($name->matches(self::property('TEL')));
        self::assertTrue($name->matches(self::property('TEL', 'X-ABC')));
        self::assertTrue($name->matches(self::property('TEL', 'X-ABC-1')));
        self::assertFalse($name->matches(self::property('EMAIL')));
    }

    /**
     * And its second: a prefix matches that prefix and nothing else.
     */
    public function testANameWithAPrefixMatchesThatPrefixOnly(): void
    {
        $name = PropertyName::of('X-ABC.TEL');

        self::assertTrue($name->matches(self::property('TEL', 'X-ABC')));
        self::assertFalse($name->matches(self::property('TEL')));
        self::assertFalse($name->matches(self::property('TEL', 'X-ABC-1')));
    }

    /**
     * RFC 6350's ABNF settles the spelling in its own comment under the `group`
     * production: "Group and name are case-insensitive."
     */
    public function testBothHalvesAreMatchedWhateverTheirCase(): void
    {
        self::assertTrue(PropertyName::of('tel')->matches(self::property('TEL')));
        self::assertTrue(PropertyName::of('x-abc.tel')->matches(self::property('TEL', 'X-ABC')));
    }

    /**
     * **A name no vCard property could carry matches nothing, and needs no rule
     * of its own to do so.** RFC 6350 spells a group `1*(ALPHA / DIGIT / "-")`
     * and a name the same way, so neither may be empty and neither may hold a
     * full stop: an empty prefix, an empty name and a second full stop are all
     * names nothing has.
     */
    public function testANameNoPropertyCouldCarryMatchesNothing(): void
    {
        self::assertFalse(PropertyName::of('.TEL')->matches(self::property('TEL')));
        self::assertFalse(PropertyName::of('.TEL')->matches(self::property('TEL', 'X-ABC')));
        self::assertFalse(PropertyName::of('X-ABC.')->matches(self::property('TEL', 'X-ABC')));
        self::assertFalse(PropertyName::of('X-ABC.TEL.X')->matches(self::property('TEL', 'X-ABC')));
    }

    /**
     * **The prefix is whatever stands before the first full stop**, which makes
     * a name with two of them one unmatchable name rather than two guesses at
     * what the client meant: no real property can answer it, because the part
     * left as the name would have to carry a full stop and RFC 6350 does not
     * let a name hold one.
     */
    public function testThePrefixIsWhateverStandsBeforeTheFirstFullStop(): void
    {
        $name = PropertyName::of('A.B.TEL');

        self::assertFalse($name->matches(self::property('TEL', 'A')));
        self::assertFalse($name->matches(self::property('TEL', 'A.B')));
        self::assertTrue(PropertyName::of('A.B')->matches(self::property('B', 'A')), 'one full stop is a prefix');
    }

    private static function property(string $name, ?string $group = null): Property
    {
        return new Property($name, 'a value', [], $group);
    }
}
