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

namespace DavServices\CalDav\Filter;

use DavServices\Dav\Collation\AsciiCasemap;
use DavServices\Dav\Collation\Collation;
use DavServices\Dav\Collation\Collations;
use DavServices\Dav\Collation\UnknownCollation;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Xml\Element;

/**
 * A substring match on one value out of a calendar object (RFC 4791 §9.7.5).
 *
 *     <!ELEMENT text-match (#PCDATA)>
 *     <!ATTLIST text-match collation        CDATA "i;ascii-casemap"
 *                          negate-condition (yes | no) "no">
 *
 * The text inside the element is what the client is looking for; the value it
 * is looked for in comes from the object. The two attributes say in which
 * alphabet to compare them, and whether the client wants the components that
 * match or the ones that do not.
 *
 * ## A substring, always
 *
 * §7.5: "The comparisons used in CalDAV are all 'substring' matches, as per
 * [RFC4790], Section 4.2. Collations supported by the server MUST support
 * 'substring' match operations."
 *
 * So there is no `match-type` here. That attribute is RFC 6352 §10.5.4's
 * addition for cards, and admitting it would be answering a question CalDAV
 * does not ask.
 *
 * ## The default collation is this memo's, not the registry's
 *
 * §7.5 says it twice — "In the absence of this attribute, the server MUST use
 * the 'i;ascii-casemap' collation", and "In the absence of a collation
 * explicitly specified by the client, or if the client specifies the 'default'
 * collation identifier […] the server MUST default to using 'i;ascii-casemap'
 * as the collation" — and §9.7.5's ATTLIST writes the same name as the
 * attribute's default.
 *
 * **Cards default to something else.** RFC 6352 §8.3 names
 * `i;unicode-casemap`, which is why {@see Collations::named()} answers null
 * with that one and why the defaulting is done here rather than there: the
 * registry is a catalogue of collations, and which of them a protocol falls
 * back to is the protocol's own statement.
 *
 * ## An unknown collation is refused here, while the body is being read
 *
 * §7.8 lists `CALDAV:supported-collation` among the report's preconditions,
 * and a precondition is a reason not to run the report at all rather than a
 * remark about its outcome — so the collation is resolved at this point,
 * before any object has been fetched.
 *
 * §7.5 says what the refusal has to be: "If the client chooses a collation not
 * supported by the server, the server MUST respond with a
 * CALDAV:supported-collation precondition error response." That element is
 * CalDAV's, and {@see Collations} sits in the `Dav` layer below, which may not
 * reach up into it — so that class throws an exception carrying the
 * identifier, and the naming happens here, where the attribute was read.
 *
 * **A wildcard needs no rule of its own.** §7.5: "Wildcards (as defined in
 * [RFC4790], Section 3.2) MUST NOT be used in the collation identifier." An
 * identifier with one in it is not among the three this server has, so the
 * sentence above already refuses it.
 */
final readonly class TextMatch
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}text-match';

    private const SUPPORTED_COLLATION = '{urn:ietf:params:xml:ns:caldav}supported-collation';

    private function __construct(
        private string $lookedFor,
        private Collation $collation,
        private bool $negated,
    ) {
    }

    /**
     * @throws BadRequest If `negate-condition` says something the ATTLIST does
     *                    not admit
     * @throws Forbidden If `collation` names one this server has not got
     */
    public static function of(Element $element): self
    {
        $negate = $element->attribute('negate-condition') ?? 'no';

        if ($negate !== 'yes' && $negate !== 'no') {
            throw new BadRequest(sprintf(
                'The negate-condition of a CALDAV:text-match is "yes" or "no", not "%s": RFC 4791 §9.7.5.',
                $negate,
            ));
        }

        return new self($element->text(), self::collationOf($element), $negate === 'yes');
    }

    /**
     * Whether this value satisfies the condition.
     *
     * **The negation turns the whole answer over**, which is what §9.7.5 asks
     * for: "return a match if the text does not match, if the attribute value
     * is set to 'yes'".
     */
    public function holds(string $lookedIn): bool
    {
        $found = $this->collation->contains($this->lookedFor, $lookedIn);

        return $this->negated ? !$found : $found;
    }

    /**
     * The collation to compare in, defaulting as §7.5 says.
     *
     * @throws Forbidden If this server has not got it
     */
    private static function collationOf(Element $element): Collation
    {
        $asked = $element->attribute('collation');

        // Both ways of saying nothing in particular end at the same place, and
        // §7.5 names the place: `i;ascii-casemap`.
        if ($asked === null || $asked === Collations::ANY) {
            $asked = AsciiCasemap::IDENTIFIER;
        }

        try {
            return Collations::named($asked);
        } catch (UnknownCollation $unknown) {
            throw new Forbidden(
                sprintf(
                    'This server has no collation "%s": the CALDAV:supported-collation precondition of RFC 4791 §7.5.',
                    $unknown->identifier(),
                ),
                self::SUPPORTED_COLLATION,
            );
        }
    }
}
