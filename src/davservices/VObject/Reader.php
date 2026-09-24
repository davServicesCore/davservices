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
 * Reads an iCalendar or vCard stream into objects.
 *
 *     foreach ((new Reader($request->body()->stream()))->objects() as $object) {
 *         $object->component('VEVENT')?->property('SUMMARY')?->value();
 *     }
 *
 * This is what joins {@see Lexer}, which reads octets into content lines, to
 * {@see Component}, which holds properties and components. The shape it looks
 * for is the same in both specifications:
 *
 *     iana-comp = "BEGIN" ":" iana-token CRLF 1*contentline "END" ":" iana-token CRLF
 *
 * **A stream may carry more than one object**, which is why this answers with
 * several: `icalstream = 1*icalobject` (RFC 5545 §3.4) and
 * `vcard-entity = 1*vcard` (RFC 6350 §3.3). An address book export is a
 * thousand vCards one after another, and a reader that answered with the
 * first would drop the other nine hundred and ninety-nine.
 *
 * ## An unknown component is kept
 *
 * RFC 5545 §3.6: "Applications **MUST ignore** x-comp and iana-comp values
 * they don't recognize", and in the next breath: "SHOULD **NOT silently
 * drop** any components as that can lead to user data loss."
 *
 * Read together those are not in tension. *Ignore* means do not choke on it;
 * *do not drop* means keep it. So an unknown component is read like any
 * other, which is also why `Component` keeps no list of names it knows.
 *
 * ## Strict, and deliberately so
 *
 * Every question this raises belongs to R-VOBJ-03's two modes, and this is
 * where they first arise: an `END` that closes something else, an `END` with
 * nothing open, a file that stops early, a property outside any component.
 *
 * **All of them are refused**, and P4-06 adds the lenient side. That order is
 * deliberate: a strict reader can be made lenient, while a lenient one can
 * never be made strict again — by then nobody knows which files came to
 * depend on the leniency.
 *
 * Two things are read rather than judged, and wait for P4-06 with the rest:
 * §3.6's `1*contentline`, which makes an empty component ill-formed, and the
 * `BEGIN-param = 0" "` of RFC 6350 §6.1.1, which allows it no parameters.
 * Neither stops the object being read, so neither is this reader's to
 * refuse.
 */
final class Reader
{
    private const BEGIN = 'BEGIN';

    private const END = 'END';

    /** @var resource|string */
    private mixed $source;

    /**
     * @param resource|string $source The stream or text to read, as
     *                                {@see Lexer} takes it
     */
    public function __construct(mixed $source)
    {
        $this->source = $source;
    }

    /**
     * Every object in the stream, in order.
     *
     * @throws ParseError If the stream cannot be read as objects at all
     *
     * @return Generator<int, Component>
     */
    public function objects(): Generator
    {
        /** @var list<Component> $enclosing */
        $enclosing = [];
        $open = null;

        foreach ((new Lexer($this->source))->lines() as $line) {
            if (self::says($line, self::BEGIN)) {
                if ($open !== null) {
                    $enclosing[] = $open;
                }

                $open = new Component(self::nameIn($line));

                continue;
            }

            if (!self::says($line, self::END)) {
                // A property before the first `BEGIN` belongs nowhere:
                // RFC 5545 §3.4 has the first line of an object be
                // `BEGIN:VCALENDAR`, and there is no component for this one
                // to be an attribute of.
                if ($open === null) {
                    throw new ParseError(sprintf('"%s" stands outside any component.', $line->name()));
                }

                $open->add(Property::from($line));

                continue;
            }

            $closed = self::closing($open, $line);
            $open = array_pop($enclosing);

            if ($open === null) {
                yield $closed;

                continue;
            }

            $open->add($closed);
        }

        if ($open !== null) {
            throw new ParseError(sprintf('The stream ends while "%s" is still open.', $open->name()));
        }
    }

    /**
     * The component an `END` closes.
     *
     * The two names are compared without case, because RFC 6350 §6.1.1 and
     * §6.1.2 both say "The value is case-insensitive" and RFC 5545 §3.1 puts
     * enumerated values in the same sentence as names.
     *
     * @throws ParseError If nothing is open, or what is open is called
     *                    something else. The message carries both names: the
     *                    file has ten thousand lines in it and they are the
     *                    only thing that says where to look
     */
    private static function closing(?Component $open, ContentLine $line): Component
    {
        if ($open === null) {
            throw new ParseError(sprintf('"END:%s" closes nothing: no component is open.', $line->value()));
        }

        if (strcasecmp($open->name(), $line->value()) !== 0) {
            throw new ParseError(sprintf(
                '"END:%s" does not close "%s".',
                $line->value(),
                $open->name(),
            ));
        }

        return $open;
    }

    /**
     * The name a `BEGIN` opens, spelled as it was written.
     *
     * @throws ParseError If it names nothing. `iana-token` is
     *                    `1*(ALPHA / DIGIT / "-")`, one character at least,
     *                    and a component nobody can name is one no `END` can
     *                    close
     */
    private static function nameIn(ContentLine $line): string
    {
        if ($line->value() === '') {
            throw new ParseError('A BEGIN names the component it opens.');
        }

        return $line->value();
    }

    /**
     * Whether a line is one of the two that build the structure.
     */
    private static function says(ContentLine $line, string $name): bool
    {
        return strcasecmp($line->name(), $name) === 0;
    }
}
