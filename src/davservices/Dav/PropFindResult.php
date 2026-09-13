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

namespace DavServices\Dav;

use DavServices\Xml\Element;

/**
 * What a `PROPFIND` has been told about one resource, while it is being told.
 *
 * Several parties answer for the same resource — plugins, the node itself, and
 * later the storage that keeps its dead properties — and R-PROP-05 asks for a
 * defined order among them. The rule is that **the first answer for a property
 * stands**. Without it the last contributor to be loaded would win, which is
 * to say that the order of somebody's `require` statements would decide what a
 * client is told.
 *
 * So whoever must have the last word takes the first one instead: access
 * control refusing a property with `403` answers it, and nothing behind can
 * quietly supply the value the client was not to see.
 *
 * A refusal is an answer. A property that was never answered and was asked for
 * by name is missing, and R-DAV-04 has it reported as such — a client that
 * asked for five and was handed three has no way of telling which two were
 * dropped.
 */
final class PropFindResult
{
    /**
     * The answers so far, in the order they came.
     *
     * @var array<string, array{Element|string|null, int}>
     */
    private array $answers = [];

    /**
     * @param string $path The path inside the tree this is about
     * @param list<string> $names What was named: the properties of `DAV:prop`,
     *                            or the extras of `DAV:include` under
     *                            `allprop`
     */
    public function __construct(
        private readonly string $path,
        private readonly PropFindForm $form,
        private readonly array $names = [],
    ) {
    }

    /**
     * The path this is about.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * How the client asked.
     */
    public function form(): PropFindForm
    {
        return $this->form;
    }

    /**
     * The properties that were named.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * Is this property still worth working out?
     *
     * A contributor asks before it goes to the trouble: a property nobody
     * asked for is work thrown away, and one that has been answered already
     * belongs to whoever answered it.
     */
    public function wants(string $name): bool
    {
        if (isset($this->answers[$name])) {
            return false;
        }

        return $this->form !== PropFindForm::Named || in_array($name, $this->names, true);
    }

    /**
     * The named properties nobody has answered yet.
     *
     * This is what a node is asked for, so that a backend does not run a query
     * for what a plugin has already handed over. Under `allprop` these are the
     * extras of `DAV:include`, and under `propname` there are none: a node
     * cannot be asked for the values of properties nobody has named.
     *
     * @return list<string>
     */
    public function stillWanted(): array
    {
        $open = [];

        foreach ($this->names as $name) {
            if (!isset($this->answers[$name])) {
                $open[] = $name;
            }
        }

        return $open;
    }

    /**
     * Answers one property, unless somebody was there first.
     *
     * @param Element|string|null $value The value; an element where the
     *                                   property holds XML, which R-PROP-03
     *                                   requires it to be able to
     * @param int $status What became of it: `200` where it is being handed
     *                    over, `403` where it may not be read
     */
    public function set(string $name, Element|string|null $value, int $status = 200): void
    {
        if (isset($this->answers[$name])) {
            return;
        }

        $this->answers[$name] = [$value, $status];
    }

    /**
     * The answers grouped into the `propstat` blocks of the response.
     *
     * A resource with nothing to say still says so: RFC 4918 §14.24 has every
     * `response` carry at least one `propstat`, and a document without one is
     * a document a strict client is right to refuse.
     *
     * @return array<int, array<string, Element|string|null>>
     */
    public function byStatus(): array
    {
        $byStatus = [];

        foreach ($this->answers as $name => [$value, $status]) {
            // `propname` asked which properties there are, not what they hold.
            $byStatus[$status][$name] = $this->form === PropFindForm::NamesOnly ? null : $value;
        }

        foreach ($this->stillWanted() as $missing) {
            $byStatus[404][$missing] = null;
        }

        return $byStatus === [] ? [200 => []] : $byStatus;
    }
}
