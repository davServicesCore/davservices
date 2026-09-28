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

use Generator;

/**
 * Writes an iCalendar or vCard object back out.
 *
 *     echo (new Serializer())->write($calendar);
 *
 * **This closes the circle**: {@see Lexer} reads octets into lines,
 * {@see Component} holds them as a tree, {@see Reader} builds the tree from a
 * file, and this writes it back.
 *
 * ## The folding
 *
 * - RFC 5545 §3.1: "Lines of text SHOULD NOT be longer than 75 octets,
 *   excluding the line break."
 * - RFC 6350 §3.2: "Content lines SHOULD be folded to a maximum width of 75
 *   octets, excluding the line break. **Multi-octet characters MUST remain
 *   contiguous.**" And: "The folded line MUST contain at least one
 *   character."
 *
 * **Octets, not characters.** A line of forty emoji is a hundred and sixty
 * octets and has to be folded, and folding it between the octets of one
 * character produces a file no reader can decode. The continuation's own
 * leading space counts towards its seventy-five, which is why the first line
 * may carry one octet more than the rest.
 *
 * ## What is written as it stands, and what is written afresh
 *
 * **A property value is written exactly as it was read.** {@see Property}
 * keeps it raw and says why: the escaping belongs to one value type, and the
 * value types of `VObject\Value` are for callers who mean to change
 * something. A serialiser that re-encoded every value would rewrite files
 * nobody had edited — and would double every backslash each time one was
 * saved.
 *
 * **A parameter value is written afresh**, and for the same reason read the
 * other way: {@see ContentLine} decoded it, taking the quotes off and undoing
 * RFC 6868's `^` sequences, so writing it back is putting back what reading
 * took away.
 */
final class Serializer
{
    /**
     * RFC 5545 §3.1 and RFC 6350 §3.2, in octets and excluding the break.
     */
    public const WIDTH = 75;

    private const BREAK = "\r\n";

    private const CONTINUATION = ' ';

    /**
     * What a `quoted-string` is needed for: `SAFE-CHAR` leaves these out, so
     * a `paramtext` cannot hold one.
     */
    private const NEEDS_QUOTING = ";:,";

    /**
     * RFC 6868 §3, in the order it has to be applied: the circumflex first,
     * or a value holding one would have its own escape escaped.
     */
    private const ESCAPES = [
        '^' => '^^',
        '"' => "^'",
        "\r\n" => '^n',
        "\n" => '^n',
        "\r" => '^n',
    ];

    /**
     * The whole object as text.
     */
    public function write(Component $object): string
    {
        $written = '';

        foreach ($this->lines($object) as $line) {
            $written .= $line;
        }

        return $written;
    }

    /**
     * The object as physical lines, folded and each ending in CRLF.
     *
     * Handed over one at a time so that a calendar of ten megabytes can be
     * written to a stream without being built in memory first.
     *
     * @return Generator<int, string>
     */
    public function lines(Component $object): Generator
    {
        yield from self::folded('BEGIN:' . $object->name());

        foreach ($object->children() as $child) {
            if ($child instanceof Component) {
                yield from $this->lines($child);

                continue;
            }

            yield from self::folded(self::contentLineOf($child));
        }

        yield from self::folded('END:' . $object->name());
    }

    /**
     * One property as a content line, unfolded.
     */
    private static function contentLineOf(Property $property): string
    {
        $group = $property->group();
        $written = ($group === null ? '' : $group . '.') . $property->name();

        foreach ($property->parameters() as $parameter) {
            $written .= ';' . $parameter->name() . self::valuesOf($parameter);
        }

        return $written . ':' . $property->value();
    }

    /**
     * `"=" param-value *("," param-value)`, or nothing at all where the
     * parameter was written without a value — which is what vCard 2.1's
     * `TEL;HOME:` is made of.
     */
    private static function valuesOf(Parameter $parameter): string
    {
        $values = $parameter->values();

        if ($values === []) {
            return '';
        }

        $written = [];

        foreach ($values as $value) {
            $written[] = self::parameterValue($value);
        }

        return '=' . implode(',', $written);
    }

    /**
     * One parameter value, escaped by RFC 6868 and quoted where `SAFE-CHAR`
     * leaves no other way to write it.
     */
    private static function parameterValue(string $value): string
    {
        $escaped = $value;

        foreach (self::ESCAPES as $character => $escape) {
            $escaped = str_replace($character, $escape, $escaped);
        }

        return strcspn($escaped, self::NEEDS_QUOTING) === strlen($escaped) ? $escaped : '"' . $escaped . '"';
    }

    /**
     * One content line as the physical lines it is written on.
     *
     * @return Generator<int, string>
     */
    private static function folded(string $line): Generator
    {
        $at = 0;

        do {
            // The continuation's own space counts towards its seventy-five,
            // which is why the first line may carry one octet more.
            $prefix = $at === 0 ? '' : self::CONTINUATION;
            $take = self::octetsToTake($line, $at, self::WIDTH - strlen($prefix));

            yield $prefix . substr($line, $at, $take) . self::BREAK;

            $at += $take;
        } while ($at < strlen($line));
    }

    /**
     * How many octets may go on this line without cutting a character in
     * half.
     *
     * RFC 6350 §3.2: "Multi-octet characters MUST remain contiguous." A
     * continuation octet of UTF-8 is `10xxxxxx`, so backing off while the
     * next octet is one of those lands on the start of a character — and the
     * line before it therefore holds whole ones.
     */
    private static function octetsToTake(string $line, int $at, int $width): int
    {
        $left = strlen($line) - $at;

        if ($left <= $width) {
            return $left;
        }

        $take = $width;

        while ($take > 1 && (ord($line[$at + $take]) & 0xC0) === 0x80) {
            --$take;
        }

        return $take;
    }
}
