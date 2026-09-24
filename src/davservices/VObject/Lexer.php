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
 * Reads an iCalendar or vCard object into unfolded content lines.
 *
 *     foreach ((new Lexer($request->body()->stream()))->lines() as $line) {
 *         $line->name();   // 'BEGIN', 'VERSION', 'SUMMARY', …
 *     }
 *
 * **Unfolding comes before everything else.** RFC 5545 §3.1: "When parsing a
 * content line, folded lines MUST first be unfolded according to the
 * unfolding procedure described above." The three specifications that govern
 * what this library reads say it in the same words:
 *
 * - RFC 5545 §3.1 (iCalendar): "Any sequence of CRLF followed immediately by
 *   a single linear white-space character is ignored (i.e., removed) when
 *   processing the content type."
 * - RFC 2425 §5.8.1, which vCard 3.0 adopts by reference (RFC 2426 §2.4.2):
 *   the same sentence.
 * - RFC 6350 §3.2 (vCard 4.0): "the CRLF and single white space character
 *   are removed".
 *
 * **vCard 2.1 has no RFC.** Where three specifications agree and none
 * disagrees, one rule is what this reads by; anything 2.1 turns out to do
 * differently is a repair for the lenient mode (R-VOBJ-03, P4-06) rather
 * than a second lexer.
 *
 * ## Three line endings, not one
 *
 * Every one of those specifications says CRLF, and each says it about
 * **writing** a file. Read only CRLF and a file saved by any Unix tool
 * becomes one enormous line, so CRLF, LF and a lone CR all delimit here
 * (R-VOBJ-01 asks for exactly that). Refusing the ones the grammar does not
 * have is a judgement about the input, and a judgement needs the input to
 * have been read first — that is the strict mode of R-VOBJ-03, and it comes
 * in P4-06.
 *
 * ## What is held in memory
 *
 * R-VOBJ-02: a file over ten megabytes may not cost memory in proportion.
 * **One logical line at a time is what streaming can honestly mean here**: a
 * `PHOTO` carrying an inline image is a single content line, and there is no
 * reading it without having it. What must never be held is the file.
 *
 * The line is collected in pieces and joined once. A `PHOTO` of a megabyte
 * arrives in fourteen thousand of them, and a reader that joined each piece
 * as it came would copy the whole line fourteen thousand times.
 */
final class Lexer
{
    /**
     * How much is read at once. It is public because the interesting faults
     * of a chunked reader are the ones at the boundary, and a test has to be
     * able to put one there.
     */
    public const CHUNK = 8192;

    /**
     * Deciding what a line ending means needs the ending itself — one octet
     * or two — and the character after it.
     */
    private const LOOKAHEAD = 3;

    private const BREAKS = "\r\n";

    /** @var resource|string */
    private mixed $source;

    private int $read = 0;

    /**
     * @param resource|string $source The object to read, as a stream or as
     *                                the text itself. A string is read in
     *                                the same steps as a stream, so that
     *                                what is tested is what runs
     */
    public function __construct(mixed $source)
    {
        $this->source = $source;
    }

    /**
     * Every content line of the object, unfolded, in order.
     *
     * @throws ParseError If a line cannot be read at all
     *
     * @return Generator<int, ContentLine>
     */
    public function lines(): Generator
    {
        foreach ($this->unfolded() as $line) {
            yield ContentLine::of($line);
        }
    }

    /**
     * The logical lines, with the folding taken out.
     *
     * **The fold is removed from the octets**, before anything is read as
     * text. RFC 5545 §3.1 asks for exactly that: "It is possible for very
     * simple implementations to generate improperly folded lines in the
     * middle of a UTF-8 multi-octet sequence. For this reason,
     * implementations need to unfold lines in such a way to properly restore
     * the original sequence." A reader that decoded first would find a
     * broken character and have nothing left to repair it with.
     *
     * **An empty line is passed over.** Every grammar here ends an object
     * with `… CRLF`, so a file that is exactly right ends with a line
     * ending — and a reader that yielded what follows it would report an
     * empty line for every correctly written file there is.
     *
     * @return Generator<int, string>
     */
    private function unfolded(): Generator
    {
        // The first read happens before the loop rather than inside it, so
        // that the loop always has something to decide about. Starting empty
        // and falling into the top-up branch would work too, and would leave
        // the initial positions below standing for nothing.
        $buffer = $this->chunk();
        $ended = $buffer === '';
        $pieces = [];
        $at = 0;
        $from = 0;

        while (true) {
            $break = $at + strcspn($buffer, self::BREAKS, $at);

            if (!$ended && strlen($buffer) - $break < self::LOOKAHEAD) {
                // Not enough to decide yet. What has been scanned is put
                // aside so that the buffer stays the size of a chunk however
                // long the line turns out to be.
                $pieces[] = substr($buffer, $from, $break - $from);
                $buffer = substr($buffer, $break);
                $at = 0;
                $from = 0;

                $chunk = $this->chunk();
                $ended = $chunk === '';
                $buffer .= $chunk;

                continue;
            }

            if ($break === strlen($buffer)) {
                // The last line of a file that ends without one, which every
                // grammar writes but not every writer does.
                $pieces[] = substr($buffer, $from);

                break;
            }

            $ending = str_starts_with(substr($buffer, $break, 2), "\r\n") ? 2 : 1;
            $pieces[] = substr($buffer, $from, $break - $from);

            // "CRLF followed immediately by a single linear white-space
            // character" — a single one. A reader that ate the run of them
            // would quietly reformat somebody's text.
            $folded = in_array(substr($buffer, $break + $ending, 1), [' ', "\t"], true);
            $at = $from = $break + $ending + ($folded ? 1 : 0);

            if ($folded) {
                continue;
            }

            $line = implode('', $pieces);
            $pieces = [];

            if ($line !== '') {
                yield $line;
            }
        }

        $line = implode('', $pieces);

        if ($line !== '') {
            yield $line;
        }
    }

    /**
     * The next piece of the object, or the empty string at its end.
     */
    private function chunk(): string
    {
        if (is_string($this->source)) {
            $chunk = substr($this->source, $this->read, self::CHUNK);
            $this->read += strlen($chunk);

            return $chunk;
        }

        // `fread` is typed `string|false`, and the false is for a handle that
        // is no stream at all — which PHP 8 raises a TypeError for rather
        // than returning from. A stream with nothing to give, even a
        // write-only one, answers the empty string. So this says "no octets"
        // without a branch no test could ever reach.
        return (string) fread($this->source, self::CHUNK);
    }
}
