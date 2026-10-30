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

namespace DavServices\Tests\Unit\CalDav;

use Closure;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\IShareTarget;
use DavServices\CalDav\Sharee;

/**
 * A store whose sharee list can really be changed.
 *
 * {@see MemoryShareable} is the read-only half and stays that way, because a
 * calendar that may be shared and one whose list a client may change are two
 * different things — §5.5.2 of `caldav-sharing` has a server treat some
 * calendars as "always public (always shared)", and that is a list nobody
 * changes by request.
 */
final class MemoryShareTarget extends MemoryShareable implements IShareTarget
{
    /** @var Closure(Sharee): ?Sharee */
    private readonly Closure $whenShared;

    /**
     * @param string|null $owner The `DAV:owner` to keep, as
     *                           {@see MemoryShareable} takes it
     * @param list<Sharee> $sharees Whom it is shared with to begin with
     * @param list<string> $modes What §5.2.3 allows on it
     * @param callable(Sharee): ?Sharee|null $whenShared What the store makes
     *                                                   of a sharee handed to it — null to decline
     *                                                   the change altogether, which is how a store
     *                                                   refuses without saying so
     */
    public function __construct(
        string $name,
        ?string $owner = '/principals/alice/',
        array $sharees = [],
        array $modes = [IShareable::CAN_BE_SHARED],
        ?callable $whenShared = null,
    ) {
        parent::__construct($name, $sharees, $modes, $owner);

        $this->whenShared = $whenShared === null
            ? static fn (Sharee $sharee): Sharee => $sharee
            : $whenShared(...);
    }

    public function shareWith(Sharee $sharee): void
    {
        $kept = ($this->whenShared)($sharee);

        if ($kept === null) {
            return;
        }

        foreach ($this->sharees as $at => $already) {
            if ($already->href() === $kept->href()) {
                $this->sharees[$at] = $kept;

                return;
            }
        }

        $this->sharees[] = $kept;
    }

    public function stopSharingWith(string $href): void
    {
        $this->sharees = array_values(array_filter(
            $this->sharees,
            static fn (Sharee $sharee): bool => $sharee->href() !== $href,
        ));
    }
}
