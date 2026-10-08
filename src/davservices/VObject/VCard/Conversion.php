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

namespace DavServices\VObject\VCard;

use DavServices\VObject\Component;
use DavServices\VObject\Finding;

/**
 * What a conversion came to: the card, and what could not come with it.
 *
 * **The losses are the point of the pair.** R-VOBJ-08 asks for "dokumentierte
 * Verluste" and R-CARD-06 for a conversion that is "verlustarm" — which is a
 * promise about how much is carried, not that everything is. A conversion that
 * dropped a `LABEL` in silence would be worse than one that refused outright,
 * because nobody downstream would ever find out.
 *
 * **Only what was left behind is in here.** A value that came across in
 * another shape — a `TYPE=pref` that became a `PREF`, a base64 photo that
 * became a data URI — is not a loss and is not listed; a reader can tell the
 * two apart by looking at the card.
 */
final class Conversion
{
    /**
     * @param list<Finding> $losses
     */
    public function __construct(
        private readonly Component $card,
        private readonly array $losses,
    ) {
    }

    /**
     * The converted card, which is a new one: the card handed in is untouched.
     */
    public function card(): Component
    {
        return $this->card;
    }

    /**
     * What the other version has no room for, one finding per place.
     *
     * @return list<Finding>
     */
    public function losses(): array
    {
        return $this->losses;
    }
}
