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

namespace DavServices\CardDav;

use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;

/**
 * Whether a node of the tree is a card, and which card (RFC 6352 §5.1).
 *
 * > Address object resources contained in address book collections MUST contain
 * > a single vCard component only.
 *
 * **One sentence, and both reports of §8.6 and §8.7 lean on it** — a query
 * searches address objects and a multiget retrieves them, so each needs the
 * same answer to "is this one, and what is in it". Asked in one place, because
 * two copies of the answer would be two chances to disagree about what a
 * client's address book holds.
 *
 * **What is not one is passed over rather than reported.** §8.6 has the
 * response carry "a DAV:response element for each address object that matched
 * the search filter", so a query simply does not see anything else; a multiget,
 * which was asked about a particular href, says so for that href instead. Five
 * ways of not being one reach here: no vCard at all, two of them, none at all,
 * a component of another kind, and a member that is no file to read.
 */
final class AddressObject
{
    private const VCARD = 'VCARD';

    /**
     * The one vCard this node holds, or null where it is not an address
     * object.
     */
    public static function of(INode $node): ?Component
    {
        if (!$node instanceof IFile) {
            return null;
        }

        return self::in($node->get());
    }

    /**
     * The one vCard this text holds, or null where it is not a card.
     *
     * The same question asked of bytes rather than of a node, because §6.3.2.1
     * asks it of "the resource submitted in the PUT request" — which is a body
     * on its way in and has no node yet.
     *
     * @param resource|string $text As {@see Reader} takes it
     */
    public static function in(mixed $text): ?Component
    {
        $card = null;

        try {
            foreach ((new Reader($text))->objects() as $object) {
                if ($card !== null) {
                    // A second one is read far enough to know that it is there
                    // and no further: whatever else the file holds, §5.1 has
                    // already been broken and nothing in it is an address
                    // object.
                    return null;
                }

                $card = $object;
            }
        } catch (ParseError) {
            return null;
        }

        // **Compared without regard to case**, because RFC 6350 §6.1.1 says so
        // of this very value: "The content entity MUST begin with the BEGIN
        // property with a value of 'VCARD'. The value is case-insensitive."
        // §6.1.2 says the same of `END` and §3.3 of every name, and
        // {@see \DavServices\VObject\Component} already looks them up that way —
        // a reader that answered only to the shouted spelling would refuse a
        // quiet client's card as no card at all.
        if ($card === null || strcasecmp($card->name(), self::VCARD) !== 0) {
            return null;
        }

        return $card;
    }
}
