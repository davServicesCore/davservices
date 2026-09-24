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

namespace DavServices\Tests\Unit\VObject;

use DavServices\VObject\Parameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.1 and §3.2, and RFC 6350 §5.
 *
 * **One parameter, with everything it says.** `param = param-name "="
 * param-value *("," param-value)` — so a parameter is a name and a list, and
 * the list may be empty where vCard 2.1 wrote a bare `;HOME`.
 *
 * It is a value object and holds no rules of its own. What a particular
 * parameter *means* is the property's business (`TZID` names a time zone,
 * `VALUE` names a type), and what may be spelled how is the validation of
 * P4-06.
 */
#[CoversClass(Parameter::class)]
final class ParameterTest extends TestCase
{
    /**
     * `param-name "=" param-value *("," param-value)`.
     */
    public function testHoldsANameAndItsValues(): void
    {
        $parameter = new Parameter('TYPE', ['work', 'voice']);

        self::assertSame('TYPE', $parameter->name());
        self::assertSame(['work', 'voice'], $parameter->values());
    }

    /**
     * **The first value, for the many parameters that have only one.**
     * `TZID` names one time zone, `VALUE` names one type; a caller asking
     * about those should not have to take a list apart.
     */
    public function testTheFirstValueIsThereForTheAsking(): void
    {
        self::assertSame('work', (new Parameter('TYPE', ['work', 'voice']))->value());
    }

    /**
     * **And a parameter with no values at all answers null**, which is what
     * vCard 2.1's `TEL;HOME;VOICE:` leaves behind. Null and the empty string
     * are different answers: one says the parameter said nothing, the other
     * that it said nothing *in particular*.
     */
    public function testAParameterWithNoValuesHasNoFirstOne(): void
    {
        $parameter = new Parameter('HOME');

        self::assertNull($parameter->value());
        self::assertSame([], $parameter->values());
    }

    /**
     * An empty value is a value, and is not the same as having none — a
     * `quoted-string` of `""` says so deliberately.
     */
    public function testAnEmptyValueIsStillAValue(): void
    {
        self::assertSame('', (new Parameter('X-A', ['']))->value());
    }

    /**
     * **RFC 5545 §3.5: "Property names, parameter names, and enumerated
     * parameter values are case-insensitive."** So a caller asking for
     * `TZID` means the one somebody wrote as `tzid`.
     */
    public function testIsFoundByNameWhateverItsSpelling(): void
    {
        $parameter = new Parameter('tzid', ['Europe/London']);

        self::assertTrue($parameter->named('TZID'));
        self::assertTrue($parameter->named('tzid'));
        self::assertFalse($parameter->named('VALUE'));
    }

    /**
     * And the spelling it was written with is the spelling it keeps: RFC 6350
     * §3.3 recommends upper case **on output**, which is a decision for the
     * serialiser and not something to take away from the reader here.
     */
    public function testKeepsTheSpellingItWasGiven(): void
    {
        self::assertSame('tzid', (new Parameter('tzid', ['Europe/London']))->name());
    }
}
