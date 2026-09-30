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
 * ## The two modes
 *
 * **Strict is the default** (R-VOBJ-03), and refuses what it cannot read as
 * written: an `END` that closes something else, an `END` with nothing open, a
 * file that stops early, a property outside any component.
 *
 * **Lenient repairs the one of those that has a single right answer** — a
 * missing `END` — and refuses the others still. {@see Mode} sets out why the
 * line falls there, and what a repaired object means for the protocol layer
 * above. Every repair is on {@see self::repairs()}; none happens quietly.
 *
 * Two things are read rather than judged, in both modes, because they do not
 * stop the object being read: §3.6's `1*contentline`, which makes an empty
 * component ill-formed, and the `BEGIN-param = 0" "` of RFC 6350 §6.1.1,
 * which allows `BEGIN` no parameters. **The first is
 * {@see Validator}'s** — and the second cannot be anybody's from here on,
 * because this reader turns `BEGIN` and `END` into structure and the
 * parameters are gone with them. It is the one rule of the two
 * specifications this library reads past.
 */
final class Reader
{
    private const BEGIN = 'BEGIN';

    private const END = 'END';

    /** @var resource|string */
    private mixed $source;

    /** @var list<Finding> */
    private array $repairs = [];

    /**
     * @param resource|string $source The stream or text to read, as
     *                                {@see Lexer} takes it
     * @param Mode $mode Strict by default, which is R-VOBJ-03's own order:
     *                   leniency is asked for, never assumed
     */
    public function __construct(mixed $source, private readonly Mode $mode = Mode::Strict)
    {
        $this->source = $source;
    }

    /**
     * What had to be put right to read this stream at all.
     *
     * Always empty in strict mode, which refuses rather than repairs. Read it
     * **after** the objects: a stream is handed over as it is read, so a
     * repair in the last line is known only once the last line has been.
     *
     * @return list<Finding>
     */
    public function repairs(): array
    {
        return $this->repairs;
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
        $this->repairs = [];

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

            if ($open === null) {
                if ($this->mode === Mode::Strict) {
                    throw new ParseError(sprintf('"END:%s" closes nothing: no component is open.', $line->value()));
                }

                $this->leftOut($line, $line->value());

                continue;
            }

            $stays = self::staysOpen($enclosing, $open, $line);

            if ($stays !== count($enclosing) && $this->mode === Mode::Strict) {
                throw new ParseError(sprintf(
                    '"END:%s" does not close "%s".',
                    $line->value(),
                    $open->name(),
                ));
            }

            if ($stays === null) {
                $this->leftOut($line, self::pathOf([...$enclosing, $open]) . '/' . $line->value());

                continue;
            }

            // Everything still open inside the one this `END` names was never
            // closed, so it is closed here — the same missing `END` as at the
            // end of a truncated stream, met in the middle of the file.
            $this->neverClosed(
                [...$enclosing, $open],
                $stays + 1,
                sprintf('"END:%s" closes it here.', $line->value()),
            );

            foreach (array_reverse(array_splice($enclosing, $stays)) as $outer) {
                $outer->add($open);
                $open = $outer;
            }

            $closed = $open;
            $open = array_pop($enclosing);

            if ($open === null) {
                yield $closed;

                continue;
            }

            $open->add($closed);
        }

        if ($open === null) {
            return;
        }

        if ($this->mode === Mode::Strict) {
            throw new ParseError(sprintf('The stream ends while "%s" is still open.', $open->name()));
        }

        $this->neverClosed([...$enclosing, $open], 0, 'the stream ends first.');

        foreach (array_reverse($enclosing) as $outer) {
            $outer->add($open);
            $open = $outer;
        }

        yield $open;
    }

    /**
     * How many components stay open after this `END`: it closes the innermost
     * open one of its name, and everything still open inside that one with
     * it. Null where nothing of that name is open at all.
     *
     * @param list<Component> $enclosing Everything open around `$open`,
     *                                   outermost first
     */
    private static function staysOpen(array $enclosing, Component $open, ContentLine $line): ?int
    {
        if (strcasecmp($open->name(), $line->value()) === 0) {
            return count($enclosing);
        }

        foreach (array_reverse($enclosing, true) as $depth => $component) {
            if (strcasecmp($component->name(), $line->value()) === 0) {
                return $depth;
            }
        }

        return null;
    }

    /**
     * Says that everything open past the first `$closed` components was never
     * closed, innermost first — the order the `END` lines are missing in.
     *
     * @param list<Component> $open Everything open, outermost first
     */
    private function neverClosed(array $open, int $closed, string $instead): void
    {
        for ($depth = count($open); $depth > $closed; --$depth) {
            $this->repairs[] = new Finding(
                Severity::Repair,
                self::pathOf(array_slice($open, 0, $depth)),
                'No END closes this component; ' . $instead,
            );
        }
    }

    /**
     * Leaves out an `END` that closes nothing. It carries no data of its own
     * — its value is the name of a component that was never opened — so
     * nothing goes with it.
     */
    private function leftOut(ContentLine $line, string $where): void
    {
        $this->repairs[] = new Finding(
            Severity::Repair,
            $where,
            sprintf('"END:%s" closes nothing that is open, and is left out.', $line->value()),
        );
    }

    /**
     * Where a component is, spelled the way {@see Finding} spells a place.
     *
     * @param list<Component> $components outermost first
     */
    private static function pathOf(array $components): string
    {
        $names = [];

        foreach ($components as $component) {
            $names[] = $component->name();
        }

        return implode('/', $names);
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
