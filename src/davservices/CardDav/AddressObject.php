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

        $card = null;

        try {
            foreach ((new Reader($node->get()))->objects() as $object) {
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

        if ($card === null || $card->name() !== self::VCARD) {
            return null;
        }

        return $card;
    }
}
