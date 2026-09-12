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

namespace DavServices\Http;

/**
 * The header fields of one message.
 *
 * Field names are looked up regardless of case (RFC 9110 §5.1) while the
 * spelling a field arrived with is kept for the way out. A field may appear
 * more than once and then holds more than one value: `DAV: 1` next to
 * `DAV: 3` says both, and collapsing them would change the meaning.
 *
 * The collection is immutable. The same one is handed to every plugin in
 * turn, and one that a plugin could alter behind the back of its holder would
 * make a response impossible to reason about; `with()`, `withAdded()` and
 * `without()` therefore return a new collection.
 */
final class Headers
{
    /**
     * Keyed by the lower-case name, so that a lookup needs no search.
     *
     * @var array<string, array{name: string, values: list<string>}>
     */
    private readonly array $fields;

    /**
     * @param array<string, string|list<string>> $headers Name as it is to be
     *                                                    spelled on the wire
     *
     * @throws MalformedHeader If a name is no token or a value carries a
     *                         control character
     */
    public function __construct(array $headers = [])
    {
        $fields = [];

        foreach ($headers as $name => $value) {
            $key = strtolower(self::checkedName($name));
            $values = is_array($value) ? $value : [$value];

            $fields[$key] ??= ['name' => $name, 'values' => []];

            foreach ($values as $single) {
                $fields[$key]['values'][] = self::checkedValue($name, $single);
            }
        }

        $this->fields = $fields;
    }

    /**
     * Is the field present, whatever case it is asked for in?
     */
    public function has(string $name): bool
    {
        return isset($this->fields[strtolower($name)]);
    }

    /**
     * The first value of the field, or null when it is absent.
     *
     * Most fields carry one value, and this is the accessor for them.
     */
    public function first(string $name): ?string
    {
        return $this->fields[strtolower($name)]['values'][0] ?? null;
    }

    /**
     * Every value of the field, in the order they arrived.
     *
     * @return list<string> Empty when the field is absent
     */
    public function all(string $name): array
    {
        return $this->fields[strtolower($name)]['values'] ?? [];
    }

    /**
     * The same fields, with this one carrying these values and no others.
     *
     * The field takes the spelling given here, and moves to the end. Neither
     * matters on the wire: RFC 9110 §5.3 makes the order of differently named
     * fields insignificant, and names are compared without regard to case.
     *
     * @throws MalformedHeader
     */
    public function with(string $name, string ...$values): self
    {
        $headers = $this->without($name)->toArray();
        $headers[$name] = array_values($values);

        return new self($headers);
    }

    /**
     * The same fields, with this value added to the field rather than replacing it.
     *
     * For the fields that may repeat — `WWW-Authenticate` offering a second
     * scheme, `DAV` naming another compliance class.
     *
     * @throws MalformedHeader
     */
    public function withAdded(string $name, string $value): self
    {
        $headers = $this->toArray();
        $key = strtolower($name);
        $spelling = $this->fields[$key]['name'] ?? $name;

        $headers[$spelling] = [...$this->all($name), $value];

        return new self($headers);
    }

    /**
     * The same fields, without this one.
     */
    public function without(string $name): self
    {
        $key = strtolower($name);

        if (!isset($this->fields[$key])) {
            return $this;
        }

        $headers = $this->toArray();
        unset($headers[$this->fields[$key]['name']]);

        return new self($headers);
    }

    /**
     * Every field, keyed by the spelling it is to be written with.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $headers = [];

        foreach ($this->fields as $field) {
            $headers[$field['name']] = $field['values'];
        }

        return $headers;
    }

    /**
     * A field name has to be a token of RFC 9110 §5.1 — nothing else can be
     * written followed by a colon and still mean what it said.
     *
     * @throws MalformedHeader
     */
    private static function checkedName(string $name): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new MalformedHeader(sprintf('"%s" is not a valid header field name.', $name));
        }

        return $name;
    }

    /**
     * A value may hold no control character but the tab (RFC 9110 §5.5), and
     * the whitespace around it is not part of it.
     *
     * A carriage return or a line feed would end the field and begin another
     * one — header injection is that one character, so it is refused rather
     * than stripped: a value that was tampered with is not a value to send.
     *
     * @throws MalformedHeader
     */
    private static function checkedValue(string $name, string $value): string
    {
        $trimmed = trim($value, " \t");

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $trimmed) === 1) {
            throw new MalformedHeader(sprintf('The value of "%s" contains a control character.', $name));
        }

        return $trimmed;
    }
}
