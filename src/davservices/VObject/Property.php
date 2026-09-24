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

namespace DavServices\VObject;

use ArrayAccess;

/**
 * One property of a component: a content line that has been understood.
 *
 * RFC 5545 §3.5: "A property is the definition of an individual attribute
 * describing a calendar object or a calendar component. A property takes the
 * form defined by the `contentline` notation defined in Section 3.1." So this
 * is the same four things {@see ContentLine} reads — group, name, parameters,
 * value — with the parameters turned into objects.
 *
 *     $property = Property::from(ContentLine::of('DTSTART;TZID=Europe/London:19980714T120000'));
 *     $property->name();                  // 'DTSTART'
 *     $property['TZID']?->value();        // 'Europe/London'
 *     $property->value();                 // '19980714T120000'
 *
 * **The value is still raw text.** `\n`, `\,` and `\;` are the escaping of
 * the `TEXT` value type alone (RFC 5545 §3.3.11); a `DATE-TIME`, a `URI` or
 * an `INTEGER` has none. Undoing it before the type is known would corrupt
 * everything that is not text, so the typed values of P4-03 will read this
 * rather than this reading them.
 *
 * **Looking a parameter up is case-insensitive**, because RFC 5545 §3.5 says
 * so in as many words and gives the example:
 * `DTSTART;TZID=America/New_York:19980714T120000` "is the same as"
 * `DtStart;TzID=America/New_York:19980714T120000`. The spelling that was
 * written is kept, because it has to come back out again.
 *
 * **It can be changed**, because a calendar object is a document people edit.
 * Rebuilding a property to correct a summary would make every edit a copy of
 * everything around it.
 *
 * @implements ArrayAccess<string, Parameter>
 */
final class Property implements ArrayAccess
{
    /** @var list<Parameter> */
    private array $parameters;

    /**
     * @param list<Parameter> $parameters
     * @param string|null $group The vCard group this was written under
     *                           (RFC 6350 §3.3), or null where there is none.
     *                           iCalendar has no such construct
     */
    public function __construct(
        private readonly string $name,
        private string $value,
        array $parameters = [],
        private readonly ?string $group = null,
    ) {
        $this->parameters = $parameters;
    }

    /**
     * Builds one from a line the lexer read.
     */
    public static function from(ContentLine $line): self
    {
        $parameters = [];

        foreach ($line->parameters() as $name => $values) {
            $parameters[] = new Parameter($name, $values);
        }

        return new self($line->name(), $line->value(), $parameters, $line->group());
    }

    /**
     * The group this was written under, or null where there is none.
     */
    public function group(): ?string
    {
        return $this->group;
    }

    /**
     * The property name, spelled as it was written.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The value, exactly as it was written.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Changes the value.
     */
    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    /**
     * Puts one more parameter at the end.
     *
     * `$property[] = $parameter` says the same thing, and reads better in a
     * run of them.
     */
    public function add(Parameter $parameter): void
    {
        $this->parameters[] = $parameter;
    }

    /**
     * Every parameter, in the order they were written.
     *
     * @return list<Parameter>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * One parameter by name, whatever its spelling, or null where there is
     * none of that name.
     *
     * Where a name was written more than once this answers the first, which
     * is what a caller asking for "the" time zone means.
     */
    public function parameter(string $name): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->named($name)) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Whether a parameter of that name is there (R-VOBJ-07).
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->parameter($offset) !== null;
    }

    /**
     * The parameter of that name, or null (R-VOBJ-07).
     *
     * @return Parameter|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->parameter($offset);
    }

    /**
     * Puts a parameter where the one of that name stood, or adds it where
     * there was none, and leaves no others of that name.
     *
     * **In place, not at the end.** Nothing in either grammar makes the order
     * of parameters mean anything, and it is kept all the same: a file that
     * comes back with its parts shuffled is one somebody has to read again to
     * see that only the one thing changed (R-VOBJ-05).
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->add($value);

            return;
        }

        $this->replace($offset, $value);
    }

    /**
     * Takes away every parameter of that name.
     *
     * Taking away one that was never there is not an error, the same as
     * everywhere else in PHP: a caller making sure a `DTSTART` is floating
     * should not have to look first.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    /**
     * **This is where a wrong type is turned away**, and PHP itself does the
     * turning: `ArrayAccess` takes `mixed` and an implementation may not
     * narrow that, so the narrowing happens one step in, where a native type
     * can state it. A caller writing `$property['TZID'] = 'Europe/London'`
     * gets a `TypeError` naming this method, rather than a property quietly
     * holding a string that breaks somewhere else entirely.
     */
    private function replace(string $name, Parameter $value): void
    {
        $kept = [];
        $replaced = false;

        foreach ($this->parameters as $parameter) {
            if (!$parameter->named($name)) {
                $kept[] = $parameter;

                continue;
            }

            if (!$replaced) {
                $kept[] = $value;
                $replaced = true;
            }
        }

        if (!$replaced) {
            $kept[] = $value;
        }

        $this->parameters = $kept;
    }

    private function remove(string $name): void
    {
        $kept = [];

        foreach ($this->parameters as $parameter) {
            if (!$parameter->named($name)) {
                $kept[] = $parameter;
            }
        }

        $this->parameters = $kept;
    }
}
