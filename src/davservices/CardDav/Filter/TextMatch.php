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

namespace DavServices\CardDav\Filter;

use DavServices\Dav\Collation\Collation;
use DavServices\Dav\Collation\Collations;
use DavServices\Dav\Collation\UnknownCollation;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Xml\Element;

/**
 * A substring match on one value from a card (RFC 6352 §10.5.4).
 *
 *     <!ELEMENT text-match (#PCDATA)>
 *     <!ATTLIST text-match
 *        collation        CDATA "i;unicode-casemap"
 *        negate-condition (yes | no) "no"
 *        match-type (equals|contains|starts-with|ends-with) "contains">
 *
 * The text inside the element is what the client is looking for; the value it
 * is looked for in comes from the card. The three attributes say in which
 * alphabet to compare them, where in the value to look, and whether the
 * client wants the cards that match or the ones that do not.
 *
 * **An unknown collation is refused here, while the body is being read.** §8.6
 * lists `CARDDAV:supported-collation` among the report's preconditions, and a
 * precondition is a reason not to run the report at all rather than a remark
 * about its outcome — so resolving the collation at this point, before any
 * card has been fetched, is what makes the refusal a precondition.
 *
 * §8.3 says what the refusal has to be: "If the client chooses a collation not
 * supported by the server, the server MUST respond with a
 * CARDDAV:supported-collation precondition error response." That element is
 * CardDAV's, and {@see Collations} sits in the `Dav` layer below, which may not
 * reach up into it — so that class throws an exception carrying the identifier,
 * and the naming happens here, where the attribute was read.
 */
final class TextMatch
{
    public const NAME = '{urn:ietf:params:xml:ns:carddav}text-match';

    private const SUPPORTED_COLLATION = '{urn:ietf:params:xml:ns:carddav}supported-collation';

    private function __construct(
        private readonly string $lookedFor,
        private readonly Collation $collation,
        private readonly MatchType $type,
        private readonly bool $negated,
    ) {
    }

    /**
     * @throws BadRequest If `negate-condition` or `match-type` says something
     *                    the DTD does not admit
     * @throws Forbidden If `collation` names one this server has not got
     */
    public static function of(Element $element): self
    {
        return new self(
            $element->text(),
            self::collationOf($element->attribute('collation')),
            MatchType::of($element->attribute('match-type')),
            self::negationOf($element->attribute('negate-condition')),
        );
    }

    /**
     * Whether this condition holds for one value out of a card.
     *
     * **Empty text matches every value**, which is no special case but what
     * the two memos say between them: the element is `#PCDATA`, so empty text
     * is a search string like any other, and RFC 4790 §4.2.3 settles what it
     * finds — "the empty string is a substring of all strings".
     */
    public function holds(string $value): bool
    {
        $matched = $this->type->holds($this->collation, $this->lookedFor, $value);

        return $this->negated ? !$matched : $matched;
    }

    /**
     * The collation the client asked for, or the one §10.5.4 defaults to.
     *
     * @throws Forbidden If this server has not got it. §8.3 names the
     *                   precondition, and naming it is what sends a client to
     *                   `CARDDAV:supported-collation-set` to see what it may
     *                   ask for instead — where a bare failure would leave it
     *                   guessing which part of its request was the trouble
     */
    private static function collationOf(?string $identifier): Collation
    {
        try {
            return Collations::named($identifier);
        } catch (UnknownCollation $unknown) {
            throw new Forbidden(
                sprintf('This server has no collation called "%s" (RFC 6352 §8.3).', $unknown->identifier()),
                self::SUPPORTED_COLLATION,
                $unknown,
            );
        }
    }

    /**
     * Whether the client asked for the values that do *not* match.
     *
     * @throws BadRequest If the attribute says neither `yes` nor `no`. Reading
     *                    a third word as "no" would hand a client that meant
     *                    "yes" precisely the cards it was trying to leave out
     */
    private static function negationOf(?string $attribute): bool
    {
        return match ($attribute) {
            null, 'no' => false,
            'yes' => true,
            default => throw new BadRequest(sprintf(
                '"%s" is no negate-condition of RFC 6352 §10.5.4.',
                $attribute,
            )),
        };
    }
}
