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

use DavServices\CalDav\IShareable;
use DavServices\CalDav\Sharee;
use DavServices\Dav\IProperties;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;

/**
 * A collection a calendar can be made of that knows about sharing.
 *
 * **And nothing else about its own kind**, which is the ordinary shape: a
 * store that keeps events has no resource type of its own to declare, and
 * {@see \DavServices\CalDav\Calendar} takes a shorter way through for one.
 * {@see TypedShareable} is the other shape, and the two are kept apart because
 * a mutation run on 29.10.2026 showed that shorter way able to return the
 * first of its list rather than the list — which is where a derived
 * `CS:shared-owner` would go missing.
 *
 * **It keeps a `DAV:owner`**, because §5.5.5 of `caldav-sharing` makes that
 * property the one thing that says who the sharer is, and §5.3.2's `POST` is
 * refused without it. A shareable calendar that is **not** a
 * {@see \DavServices\CalDav\IShareTarget} therefore still gets as far as the
 * guard that refuses it — a second mutation run showed that guard passing for
 * the wrong reason while this double kept no properties at all.
 */
class MemoryShareable extends MemoryCollection implements IProperties, IShareable
{
    /** @var list<Sharee> */
    protected array $sharees;

    /** @var array<string, Element|string|null> */
    protected array $kept = [];

    /** @var list<string> */
    private readonly array $modes;

    /**
     * @param list<Sharee> $sharees Whom this calendar is shared with
     * @param list<string> $modes What §5.2.3 allows on it
     * @param string|null $owner What `DAV:owner` names — null for a calendar
     *                           that keeps no such property at all, and the
     *                           empty string for one that keeps it and names
     *                           nobody
     */
    public function __construct(
        string $name,
        array $sharees = [],
        array $modes = [],
        ?string $owner = '/principals/alice/',
    ) {
        parent::__construct($name);

        $this->sharees = $sharees;
        $this->modes = $modes;

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
     *
     * The address is optional there, so an empty one is a calendar that has
     * the property and names nobody with it.
     */
    private static function ownerSaying(string $href): Element
    {
        $owner = new Element('{DAV:}owner');

        if ($href !== '') {
            $names = new Element('{DAV:}href');

            $names->appendText($href);
            $owner->append($names);
        }

        return $owner;
    }
}
