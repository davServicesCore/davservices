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

/**
 * One unfolded line of an iCalendar or vCard object, read as syntax.
 *
 *     contentline = [group "."] name *(";" param) ":" value
 *
 * RFC 5545 §3.1 and RFC 6350 §3.3 give almost the same grammar, and RFC 5545
 * says why: "the content information consists of CRLF-separated content
 * lines" formatted "using a syntax similar to that defined by [RFC2425]". So
 * one reader serves iCalendar, vCard 3.0 and vCard 4.0 alike.
 *
 *     $line = ContentLine::of('ATTENDEE;CN=Ada Lovelace:mailto:ada@example.com');
 *     $line->name();             // 'ATTENDEE'
 *     $line->parameter('CN');    // ['Ada Lovelace']
 *     $line->value();            // 'mailto:ada@example.com'
 *
 * **The group belongs to vCard alone** (RFC 6350 §3.3), and is read for both
 * all the same: a `name` is `1*(ALPHA / DIGIT / "-")` in either
 * specification and so cannot hold a full stop, which means a line carrying
 * one is saying something. Dropping it would be deciding rather than
 * reading.
 *
 * ## What is decoded, and what is kept exactly as written
 *
 * **Encodings are undone**, because they are how the character got into the
 * line at all:
 *
 * - the quotes around a `quoted-string` are syntax, not content
 *   (`param-value = paramtext / quoted-string`);
 * - RFC 6868's `^` sequences are the only way a parameter value can hold a
 *   `"` or a line break — `QSAFE-CHAR` excludes the one and `CONTROL` the
 *   other.
 *
 * **Everything else is kept as it was.** Case above all: both specifications
 * make names case-insensitive, but that is a rule about *comparing* them, and
 * it is honoured where comparing happens — {@see self::parameter()}. A lexer
 * that changed the spelling would be one you cannot use to see what a file
 * says, and the spelling has to come back out again when the object is
 * written.
 *
 * **The value is raw.** `\n`, `\,` and `\;` are the escaping of one value
 * type, `TEXT` (RFC 5545 §3.3.11); a `URI`, an `INTEGER` or a `DATE-TIME` has
 * none. Undoing it here would corrupt every value that is not text, so it
 * waits for the typed values of P4-03.
 */
final class ContentLine
{
    /** Where the parameters end and the value begins. */
    private const VALUE = ':';

    /** What separates one parameter from the next. */
    private const PARAMETER = ';';

    /** What separates one value of a parameter from the next. */
    private const VALUES = ',';

    private const ASSIGNMENT = '=';

    private const GROUP = '.';

    private const QUOTE = '"';

    /**
     * RFC 6868 §3, the whole of it: what a `^` and the character after it
     * stand for. Anything else keeps both characters, which that section
     * makes a MUST.
     */
    private const ESCAPES = [
        'n' => "\n",
        '^' => '^',
        "'" => '"',
    ];

    /**
     * @param array<string, list<string>> $parameters
     */
    private function __construct(
        private readonly ?string $group,
        private readonly string $name,
        private readonly array $parameters,
        private readonly string $value,
    ) {
    }

    /**
     * Reads one line, which has already been unfolded ({@see Lexer}).
     *
     * @throws ParseError If it is no content line at all
     */
    public static function of(string $line): self
    {
        $ends = strcspn($line, self::PARAMETER . self::VALUE);

        if ($ends === strlen($line)) {
            throw new ParseError(sprintf('A content line has a value after a colon: "%s".', $line));
        }

        [$group, $name] = self::groupAndNameIn(substr($line, 0, $ends));
        $value = self::endOfTheParameters($line, $ends);

        return new self(
            $group,
            $name,
            // The leading `;` goes along: a line with no parameters at all
            // then hands over nothing rather than a length of minus one.
            self::parametersIn(substr($line, $ends, $value - $ends)),
            substr($line, $value + 1),
        );
    }

    /**
     * The group this property was written under, or null where there is
     * none (RFC 6350 §3.3).
     */
    public function group(): ?string
    {
        return $this->group;
    }

    /**
     * The property name, spelled as the line spelled it.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The property value, exactly as it was written.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Every parameter, by the name it was written under.
     *
     * @return array<string, list<string>>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * The values of one parameter, whatever its spelling.
     *
     * **This is where case-insensitivity lives.** RFC 5545 §3.1: "All names
     * of properties, property parameters, enumerated property values and
     * property parameter values are case-insensitive", and RFC 6350 §3.3
     * says the same of parameter names. A caller asking for `VALUE` means
     * the one somebody wrote as `value`.
     *
     * A parameter that is not there has no values, which is also what a
     * parameter written without any has — `TEL;HOME:` says `HOME` and says
     * nothing about it.
     *
     * @return list<string>
     */
    public function parameter(string $name): array
    {
        $found = [];

        foreach ($this->parameters as $written => $values) {
            if (strcasecmp($written, $name) === 0) {
                $found = [...$found, ...$values];
            }
        }

        return $found;
    }

    /**
     * Splits `[group "."] name` into its two halves.
     *
     * @throws ParseError If either half is missing
     *
     * @return array{string|null, string}
     */
    private static function groupAndNameIn(string $start): array
    {
        $dot = strpos($start, self::GROUP);
        $group = $dot === false ? null : substr($start, 0, $dot);
        $name = $dot === false ? $start : substr($start, $dot + 1);

        // `name` and `group` are both `1*(ALPHA / DIGIT / "-")`: one
        // character at least, in either specification.
        if ($name === '' || $group === '') {
            throw new ParseError(sprintf('A content line is named before its colon: "%s".', $start));
        }

        return [$group, $name];
    }

    /**
     * Where the value begins: the first colon that is not inside quotes.
     *
     * Quoting is the whole reason a parameter value may hold a colon at all
     * — `QSAFE-CHAR` is "any character except CONTROL and DQUOTE" — so a
     * reader that took the first colon it saw would cut `CN="a:b"` in half.
     *
     * @throws ParseError If there is none, or a quoted value never ends
     */
    private static function endOfTheParameters(string $line, int $from): int
    {
        $quoted = false;

        for ($at = $from; $at < strlen($line); ++$at) {
            if ($line[$at] === self::QUOTE) {
                $quoted = !$quoted;

                continue;
            }

            if ($line[$at] === self::VALUE && !$quoted) {
                return $at;
            }
        }

        throw new ParseError(sprintf('A quoted parameter value is never closed: "%s".', $line));
    }

    /**
     * `*(";" param)`, as written.
     *
     * The same parameter twice keeps both lots of values: RFC 6350 §5 has
     * parameters that may appear more than once, and keeping only the last
     * would quietly drop half of what a client sent.
     *
     * @return array<string, list<string>>
     */
    private static function parametersIn(string $text): array
    {
        $parameters = [];

        foreach (self::splitOutsideQuotes($text, self::PARAMETER) as $parameter) {
            if ($parameter === '') {
                continue;
            }

            [$name, $values] = self::oneParameterIn($parameter);
            $parameters[$name] = [...($parameters[$name] ?? []), ...$values];
        }

        return $parameters;
    }

    /**
     * `param-name "=" param-value *("," param-value)`, or a bare name.
     *
     * **A parameter with no value at all is kept as one.** vCard 2.1 writes
     * `TEL;HOME;VOICE:…` and this library has to read 2.1 (R-VOBJ-01);
     * turning that into `TYPE=HOME` would be the version conversion (P4-13)
     * doing its work here, and guessing which parameter was meant. So the
     * name is what was written and there are no values, which is what the
     * line says.
     *
     * The first `=` is the assignment wherever it is: `param-name` is
     * `iana-token / x-name`, and neither can hold one.
     *
     * @throws ParseError If it has no name
     *
     * @return array{string, list<string>}
     */
    private static function oneParameterIn(string $parameter): array
    {
        $assigned = strpos($parameter, self::ASSIGNMENT);
        $name = $assigned === false ? $parameter : substr($parameter, 0, $assigned);

        if ($name === '') {
            throw new ParseError(sprintf('A parameter is named before its value: "%s".', $parameter));
        }

        if ($assigned === false) {
            return [$name, []];
        }

        $values = [];

        // Called outright rather than handed to `array_map` as
        // `self::valueOf(...)`: Xdebug records no branches for a method
        // reached through a first-class callable, so the coverage gate would
        // report a function nobody had tested while every test went through
        // it.
        foreach (self::splitOutsideQuotes(substr($parameter, $assigned + 1), self::VALUES) as $value) {
            $values[] = self::valueOf($value);
        }

        return [$name, $values];
    }

    /**
     * One parameter value: the text inside the quotes where there are any,
     * with RFC 6868's escaping undone either way.
     *
     * §3 of that RFC is explicit that both forms carry it: "The ^-escaping
     * mechanism can be used when the value is either unquoted or quoted
     * (i.e., whether or not the value is surrounded by double-quotes)."
     */
    private static function valueOf(string $value): string
    {
        $quoted = strlen($value) >= 2 && str_starts_with($value, self::QUOTE) && str_ends_with($value, self::QUOTE);

        return self::unescaped($quoted ? substr($value, 1, -1) : $value);
    }

    /**
     * RFC 6868 §3, undone.
     *
     * The last clause of that section is a MUST and is what the `null` here
     * is for: "if a ^ (U+005E) character is followed by any character other
     * than the ones above, parsers MUST leave both the ^ and the following
     * character in place". A value written before that RFC existed keeps its
     * meaning.
     *
     * `^n` becomes `\n` rather than whatever this machine writes at the end
     * of a line. The RFC says "an appropriate formatted line break according
     * to the type of system being used", and a protocol library has no system
     * of its own — a value read on one server has to come out the same on
     * another.
     */
    private static function unescaped(string $value): string
    {
        $text = '';

        for ($at = 0; $at < strlen($value); ++$at) {
            $escape = self::ESCAPES[$value[$at + 1] ?? ''] ?? null;

            if ($value[$at] !== '^' || $escape === null) {
                $text .= $value[$at];

                continue;
            }

            $text .= $escape;
            ++$at;
        }

        return $text;
    }

    /**
     * Splits on a separator that only separates outside a `quoted-string`.
     *
     * @return list<string>
     */
    private static function splitOutsideQuotes(string $text, string $separator): array
    {
        $pieces = [];
        $from = 0;
        $quoted = false;

        for ($at = 0; $at < strlen($text); ++$at) {
            if ($text[$at] === self::QUOTE) {
                $quoted = !$quoted;
            }

            if ($text[$at] === $separator && !$quoted) {
                $pieces[] = substr($text, $from, $at - $from);
                $from = $at + 1;
            }
        }

        $pieces[] = substr($text, $from);

        return $pieces;
    }
}
