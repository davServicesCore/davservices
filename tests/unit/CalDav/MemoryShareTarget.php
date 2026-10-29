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
use DavServices\Dav\IProperties;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;

/**
 * A store whose sharee list can really be changed.
 *
 * {@see MemoryShareable} is the read-only half and stays that way, because a
 * calendar that may be shared and one whose list a client may change are two
 * different things — §5.5.2 of `caldav-sharing` has a server treat some
 * calendars as "always public (always shared)", and that is a list nobody
 * changes by request.
 *
 * It keeps a `DAV:owner`, because §5.5.5 makes that property the one thing
 * that says who the sharer is, and nothing may be shared without it.
 */
final class MemoryShareTarget extends MemoryCollection implements IProperties, IShareable, IShareTarget
{
    /** @var list<Sharee> */
    private array $sharees;

    /** @var list<string> */
    private readonly array $modes;

    /** @var array<string, Element|string|null> */
    private array $kept = [];

    /** @var Closure(Sharee): ?Sharee */
    private readonly Closure $whenShared;

    /**
     * @param string|null $owner The `DAV:owner` to keep, or null for a
     *                           calendar that says who owns it nowhere
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
        parent::__construct($name);

        $this->sharees = $sharees;
        $this->modes = $modes;
        $this->whenShared = $whenShared === null
            ? static fn (Sharee $sharee): Sharee => $sharee
            : $whenShared(...);

        if ($owner !== null) {
            $this->kept['{DAV:}owner'] = self::ownerSaying($owner);
        }
    }

    /**
     * @return list<string>
     */
    public function allowedSharingModes(): array
    {
        return $this->modes;
    }

    /**
     * @return list<Sharee>
     */
    public function sharees(): array
    {
        return $this->sharees;
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

    /**
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return array_keys($this->kept);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    public function properties(array $names): array
    {
        $found = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $this->kept)) {
                $found[$name] = $this->kept[$name];
            }
        }

        return $found;
    }

    /**
     * @param array<string, Element|string|null> $mutations
     *
     * @return array<string, int>
     */
    public function patchProperties(array $mutations): array
    {
        $answered = [];

        foreach ($mutations as $name => $value) {
            $this->kept[$name] = $value;
            $answered[$name] = 200;
        }

        return $answered;
    }

    /**
     * RFC 3744 §5.1: `<!ELEMENT owner (href?)>`.
     */
    private static function ownerSaying(string $href): Element
    {
        $owner = new Element('{DAV:}owner');
        $names = new Element('{DAV:}href');

        $names->appendText($href);
        $owner->append($names);

        return $owner;
    }
}
