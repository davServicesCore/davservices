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

namespace DavServices\Tests\Fuzz;

use InvalidArgumentException;

/**
 * The inputs the fuzzer tries. R-QS-19.
 *
 * **A fuzzer is only as good as its inputs, and random bytes are the weakest
 * kind**: a parser refuses them at the first character, and nothing deeper is
 * ever reached. So these start from objects the memos themselves publish —
 * RFC 6350 §8's author's vCard, RFC 2426 §7's card, RFC 5545 §3.6.5's time
 * zone — and break them in **named** ways, which is how a mutation reaches
 * the code that runs after the first line was understood. {@see seeds()} says
 * exactly where each one comes from, and where it does not.
 *
 * ## Every mutation owes three things
 *
 * - **it changes the input**, or it tests nothing;
 * - **the same seed gives the same input**, or a finding cannot be looked at
 *   twice, and that is the whole value of a finding;
 * - **different seeds give different inputs**, or a thousand runs are one run
 *   done a thousand times.
 *
 * The seeding is PHP's own `mt_srand`, which is not cryptography and does not
 * need to be: what matters is that a seed written in a report brings the same
 * input back.
 */
final class Mutations
{
    /**
     * Characters whose UTF-8 form is more than one byte long, two bytes up to
     * four, so that a half of one is a half of a different length each time.
     *
     * @var list<non-empty-string>
     */
    private const MULTI_BYTE = ["\u{00fc}", "\u{00df}", "\u{2014}", "\u{20ac}", "\u{1f382}"];

    /**
     * Values that are well formed as a content line and hostile as a value.
     *
     * **This is where a fuzzer earns its keep.** A broken byte is refused at
     * the line it is in, so the code that runs *after* an object has been
     * understood never sees it; a value like these gets all the way there and
     * then asks whether a date really has to be a date.
     *
     * Several of them are well formed by an ABNF and forbidden by the prose
     * printed beside it, which is the gap worth probing. The period below is
     * the clearest: `period-explicit = date-time "/" date-time` admits any two
     * moments, and §3.3.9 adds "The start MUST be before the end."
     *
     * @var list<string>
     */
    private const HOSTILE_VALUES = [
        '',
        '99999999T999999Z',
        '18000101T000000Z',
        '99991231T235959Z',
        '20240101T000000Z/20230101T000000Z',
        '9223372036854775807',
        '-9223372036854775808',
        '0',
        ';;;;;;;;;;',
        ',,,,,,,,,,',
        '\\n\\N\\,\\;\\\\',
        '-PT0S',
        'P0D',
        'TRUE',
        // The shapes a photograph arrives in, and the broken forms of them.
        // RFC 2397 §2 makes the media type and the ";base64" both optional,
        // so each of these is a different reading of the same grammar.
        'data:',
        'data:,',
        'data:;base64,',
        'data:image/jpeg;base64,',
        'data:image/jpeg;base64,!!!!not base64!!!!',
        'data:image/jpeg;base64,aGVsbG8',
        'data:,%zz%2',
        'https://example.com/photo.jpg',
    ];

    /**
     * Rules the grammar of §3.3.10 admits and an expansion has to survive.
     *
     * **A rule that matches nothing is the classic endless loop**: there is
     * no thirtieth of February, and no fifty-third Monday from the end of any
     * month, and `ordmoday` and `ordwk` admit both all the same. R-RRULE-04's
     * hard limit is what has to answer them, which is why {@see Run} counts
     * `TooManyIterations` as a refusal rather than a finding — it is the
     * library saying no, which is exactly what was asked of it.
     *
     * **Eleven of the twelve are read and only `INTERVAL=0` is refused**,
     * measured rather than assumed, and that is the shape it should have: the
     * rule parser turns away the one the prose forbids — `1*DIGIT` by the
     * ABNF, "a positive integer" beside it — and hands the engine every rule
     * the memo does admit, including the ones that match nothing. A catalogue
     * the parser rejected wholesale would be testing the parser twelve times
     * and the expansion never.
     *
     * @var list<non-empty-string>
     */
    private const HOSTILE_RULES = [
        'FREQ=SECONDLY;COUNT=9999',
        'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30',
        'FREQ=HOURLY;BYMONTH=2;BYMONTHDAY=29',
        'FREQ=MONTHLY;BYMONTHDAY=31',
        'FREQ=MONTHLY;BYDAY=-53MO',
        'FREQ=DAILY;UNTIL=19700101T000000Z',
        'FREQ=YEARLY;BYYEARDAY=366',
        'FREQ=WEEKLY;INTERVAL=2147483647',
        'FREQ=DAILY;INTERVAL=0',
        'FREQ=YEARLY;BYSETPOS=-1;BYDAY=MO,TU,WE,TH,FR',
        'FREQ=MINUTELY;BYSECOND=0;BYMINUTE=0;BYHOUR=0',
        'FREQ=DAILY;COUNT=0',
    ];

    /**
     * The valid objects every mutation starts from.
     *
     * Where each one comes from, and where it does not:
     *
     * - **a card** is RFC 2426 §7's first card, with two additions. One is an
     *   `N`, because §3.1.2 says of it "The property MUST be present in the
     *   vCard object" and **the memo's own example card has none** — left out,
     *   the validator would report that one finding on every input of a
     *   thousand and bury everything else. The other is a small `PHOTO`, so
     *   that the binary and `data:` paths are swept at all.
     * - **a card of 4.0** is RFC 6350 §8's author's vCard, verbatim but
     *   unfolded, with §6.2.4's own `PHOTO:data:image/jpeg;base64,…` example
     *   put in — shortened to a few bytes, its length not being what is under
     *   test. Without a 4.0 card nothing would sweep the current vCard at
     *   all, and nothing would reach the `data:` reader on every parse rather
     *   than by luck.
     * - **a card of 2.1** has no memo behind it: vCard 2.1 is Versit's, not
     *   the IETF's, and there is no RFC to quote. It is built from what
     *   {@see \DavServices\VObject\VCard\Converter} reads — the unlabelled
     *   `TYPE` parameters and the `QUOTED-PRINTABLE` encoding that version
     *   uses and no later one does.
     * - **a calendar** is built rather than quoted, to carry the four things
     *   the code after the reader computes from: an `RRULE`, an `EXDATE`, a
     *   `TZID` and a `TEXT` value with §3.3.11's escapes in it.
     * - **a time zone** is RFC 5545 §3.6.5's fifth published definition,
     *   verbatim, wrapped in the `VCALENDAR` that §3.4 requires around it.
     *
     * @return array<string, string>
     */
    public static function seeds(): array
    {
        return [
            'a card' => self::lines([
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN:Frank Dawson',
                'N:Dawson;Frank;;;',
                'ORG:Lotus Development Corporation',
                'ADR;TYPE=WORK,POSTAL,PARCEL:;;6544 Battleford Drive;Raleigh;NC;27613-3502;U.S.A.',
                'TEL;TYPE=VOICE,MSG,WORK:+1-919-676-9515',
                'EMAIL;TYPE=INTERNET,PREF:Frank_Dawson@Lotus.com',
                'PHOTO;ENCODING=b;TYPE=JPEG:aGVsbG8=',
                'URL:http://home.earthlink.net/~fdawson',
                'END:VCARD',
            ]),
            'a card of 4.0' => self::lines([
                'BEGIN:VCARD',
                'VERSION:4.0',
                'FN:Simon Perreault',
                'N:Perreault;Simon;;;ing. jr,M.Sc.',
                'BDAY:--0203',
                'ANNIVERSARY:20090808T1430-0500',
                'GENDER:M',
                'LANG;PREF=1:fr',
                'LANG;PREF=2:en',
                'ORG;TYPE=work:Viagenie',
                'ADR;TYPE=work:;Suite D2-630;2875 Laurier;Quebec;QC;G1V 2M2;Canada',
                'TEL;VALUE=uri;TYPE="work,voice";PREF=1:tel:+1-418-656-9254;ext=102',
                'TEL;VALUE=uri;TYPE="work,cell,voice,video,text":tel:+1-418-262-6501',
                'EMAIL;TYPE=work:simon.perreault@viagenie.ca',
                'GEO;TYPE=work:geo:46.772673,-71.282945',
                'KEY;TYPE=work;VALUE=uri:http://www.viagenie.ca/simon.perreault/simon.asc',
                'PHOTO:data:image/jpeg;base64,aGVsbG8=',
                'TZ:-0500',
                'URL;TYPE=home:http://nomis80.org',
                'END:VCARD',
            ]),
            'a card of 2.1' => self::lines([
                'BEGIN:VCARD',
                'VERSION:2.1',
                'FN:Frank Dawson',
                'N:Dawson;Frank;;;',
                'TEL;WORK;VOICE:+1-919-676-9515',
                'NOTE;ENCODING=QUOTED-PRINTABLE:Gr=C3=BC=C3=9Fe',
                'END:VCARD',
            ]),
            'a calendar' => self::lines([
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//davServices//Fuzz//EN',
                'BEGIN:VEVENT',
                'UID:fuzz@example.com',
                'DTSTAMP:20240101T000000Z',
                'DTSTART;TZID=Europe/Berlin:20240101T090000',
                'RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10',
                'EXDATE;TZID=Europe/Berlin:20240108T090000',
                // §3.3.11 leaves COMMA and SEMICOLON out of TEXT-CHAR, so
                // both are escaped here. Unescaped, the validator would
                // report that one finding on every input there is.
                'SUMMARY:Weekly\\, with a comma\\, an escape and a semicolon\\;',
                'END:VEVENT',
                'END:VCALENDAR',
            ]),
            'a time zone' => self::lines([
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//davServices//Fuzz//EN',
                'BEGIN:VTIMEZONE',
                'TZID:America/New_York',
                'LAST-MODIFIED:20050809T050000Z',
                'TZURL:http://zones.example.com/tz/America-New_York.ics',
                'BEGIN:STANDARD',
                'DTSTART:20071104T020000',
                'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
                'TZOFFSETFROM:-0400',
                'TZOFFSETTO:-0500',
                'TZNAME:EST',
                'END:STANDARD',
                'BEGIN:DAYLIGHT',
                'DTSTART:20070311T020000',
                'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
                'TZOFFSETFROM:-0500',
                'TZOFFSETTO:-0400',
                'TZNAME:EDT',
                'END:DAYLIGHT',
                'END:VTIMEZONE',
                'END:VCALENDAR',
            ]),
        ];
    }

    /**
     * The mutations, by the name a report calls them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'a flipped byte',
            'a cut',
            'a doubled line',
            'a dropped line',
            'an inserted control character',
            'a halved character',
            'a very long line',
            'a deep nesting',
            'a folded line',
            'a hostile value',
            'a hostile rule',
        ];
    }

    /**
     * One input, made by that mutation from that text with that seed.
     *
     * @throws InvalidArgumentException If no mutation goes by that name
     */
    public static function apply(string $mutation, string $text, int $seed): string
    {
        mt_srand($seed);

        return match ($mutation) {
            'a flipped byte' => self::flipAByte($text),
            'a cut' => substr($text, 0, max(1, mt_rand(1, max(1, strlen($text) - 1)))),
            'a doubled line' => self::doubleALine($text),
            'a dropped line' => self::dropALine($text),
            'an inserted control character' => self::insertAControlCharacter($text),
            'a halved character' => self::halveACharacter($text),
            'a very long line' => self::aVeryLongLine($text),
            'a deep nesting' => self::aDeepNesting($text),
            'a folded line' => self::foldALine($text),
            'a hostile value' => self::aHostileValue($text),
            'a hostile rule' => self::aHostileRule($text),
            default => throw new InvalidArgumentException(
                sprintf('There is no such mutation as "%s".', $mutation),
            ),
        };
    }

    /**
     * One byte somewhere else in the alphabet: what a reader does with a
     * character it did not expect in a place it knows.
     */
    private static function flipAByte(string $text): string
    {
        $at = mt_rand(0, strlen($text) - 1);
        $text[$at] = chr((ord($text[$at]) + mt_rand(1, 255)) % 256);

        return $text;
    }

    /**
     * A line written twice, which is how a card comes to carry two of
     * something that may occur once.
     */
    private static function doubleALine(string $text): string
    {
        $lines = self::linesOf($text);
        $at = mt_rand(0, count($lines) - 1);

        return self::lines(self::inserted($lines, $at, array_slice($lines, $at, 1)));
    }

    /**
     * A line gone, which takes away the things a grammar calls REQUIRED.
     */
    private static function dropALine(string $text): string
    {
        $lines = self::linesOf($text);
        $at = mt_rand(0, count($lines) - 1);

        return self::lines([...array_slice($lines, 0, $at), ...array_slice($lines, $at + 1)]);
    }

    /**
     * A byte no content line may carry. Both memos build a value out of
     * `VALUE-CHAR`, and both annotate it "Any textual character":
     *
     *     VALUE-CHAR    = WSP / %x21-7E / NON-US-ASCII   (RFC 5545 §3.1)
     *     VALUE-CHAR    = WSP / VCHAR / NON-ASCII        (RFC 6350 §3.3)
     *
     * The range stops at `%x08` because `%x09` is HTAB, which both admit as
     * `WSP`: a mutation that put a tab in would be writing a legal line and
     * calling it broken.
     */
    private static function insertAControlCharacter(string $text): string
    {
        $at = mt_rand(0, strlen($text) - 1);
        $control = chr(mt_rand(0, 8));

        return substr($text, 0, $at) . $control . substr($text, $at);
    }

    /**
     * A multi-byte character cut through the middle, which the memos make an
     * ordinary case rather than an exotic one. RFC 6350 §3.1: "The charset
     * […] for vCard is UTF-8 […] There is no way to override this." §3.2 then
     * says what a writer owes — "Multi-octet characters MUST remain
     * contiguous" — and a reader therefore meets what happens when one did
     * not.
     *
     * **The half goes at the end of a line**, which is what makes the result
     * broken for certain rather than usually: the next byte is then the CR of
     * the line break, and a CR can never complete a sequence. Dropped in the
     * middle of the text it could land in front of a byte that completes it
     * by accident, and a mutation that is only probably broken is one whose
     * failures nobody can explain.
     */
    private static function halveACharacter(string $text): string
    {
        $lines = self::linesOf($text);
        $at = mt_rand(0, count($lines) - 1);
        $half = substr(self::MULTI_BYTE[mt_rand(0, count(self::MULTI_BYTE) - 1)], 0, -1);
        $broken = [];

        foreach ($lines as $position => $line) {
            $broken[] = $position === $at ? $line . $half : $line;
        }

        return self::lines($broken);
    }

    /**
     * A line far longer than anything a memo asks for. RFC 5545 §3.1: "Lines
     * of text SHOULD NOT be longer than 75 octets, excluding the line break";
     * RFC 6350 §3.2 puts the same number the other way round, "Content lines
     * SHOULD be folded to a maximum width of 75 octets". Both are SHOULD, and
     * a reader that treated a SHOULD as a guarantee would be one that a
     * single long line could take down.
     */
    private static function aVeryLongLine(string $text): string
    {
        $lines = self::linesOf($text);
        $at = mt_rand(1, max(1, count($lines) - 1));
        $long = 'NOTE:' . str_repeat('x', 150_000 + mt_rand(0, 999));

        return self::lines(self::inserted($lines, $at, [$long]));
    }

    /**
     * Far more components opened than closed: the input that asks whether a
     * reader keeps a stack, and whether that stack has an end.
     */
    private static function aDeepNesting(string $text): string
    {
        $lines = self::linesOf($text);
        $depth = 600 + mt_rand(0, 400);

        return self::lines(self::inserted($lines, 1, array_fill(0, $depth, 'BEGIN:VEVENT')));
    }

    /**
     * A line folded where a memo allows it, and then in the places it does
     * not. RFC 5545 §3.1: "a long line can be split between any two
     * characters by inserting a CRLF immediately followed by a single linear
     * white-space character" — **any** two, which includes the middle of a
     * property name, of a parameter and of a `data:` URI.
     *
     * The position starts at one because RFC 6350 §3.2 adds the one limit
     * there is: "The folded line MUST contain at least one character."
     */
    private static function foldALine(string $text): string
    {
        $at = mt_rand(1, max(1, strlen($text) - 1));

        return substr($text, 0, $at) . "\r\n " . substr($text, $at);
    }

    /**
     * One property's value swapped for something hostile, the line itself
     * left well formed.
     *
     * **The structure is left alone on purpose.** `BEGIN`, `END` and
     * `VERSION` are what let the reader get as far as the values at all; a
     * hostile value in one of those is just another refused input, and this
     * mutation exists to produce the ones that are *not* refused.
     */
    private static function aHostileValue(string $text): string
    {
        $lines = self::linesOf($text);
        $value = self::HOSTILE_VALUES[mt_rand(0, count(self::HOSTILE_VALUES) - 1)];
        $places = 0;

        foreach ($lines as $line) {
            $places += self::holdsTheObjectTogether($line) ? 0 : 1;
        }

        if ($places === 0) {
            return self::lines(self::inserted($lines, max(1, count($lines) - 1), ['NOTE:' . $value]));
        }

        $chosen = mt_rand(1, $places);
        $seen = 0;
        $spoiled = [];

        foreach ($lines as $line) {
            if (self::holdsTheObjectTogether($line)) {
                $spoiled[] = $line;

                continue;
            }

            // The name and its parameters are kept, the value replaced: a
            // DTSTART;TZID=Europe/Berlin still asks the time zone code to
            // look that zone up.
            $spoiled[] = ++$seen === $chosen
                ? substr($line, 0, strcspn($line, ':')) . ':' . $value
                : $line;
        }

        return self::lines($spoiled);
    }

    /**
     * Whether this is one of the lines the reader needs to get as far as the
     * values at all.
     */
    private static function holdsTheObjectTogether(string $line): bool
    {
        return in_array(self::nameOf($line), ['BEGIN', 'END', 'VERSION'], true);
    }

    /**
     * Every recurrence rule swapped for one that is harder work, and a rule
     * put in where there was none.
     *
     * **It replaces rather than adds**, and RFC 5545 §3.8.5.3 says why: an
     * `RRULE` "SHOULD NOT be specified more than once. The recurrence set
     * generated with multiple 'RRULE' properties is **undefined**." A sweep
     * that produced two of them would be sweeping undefined behaviour, where
     * no answer can be called wrong — which is the one thing a fuzzer must
     * not spend its runs on.
     */
    private static function aHostileRule(string $text): string
    {
        $rule = 'RRULE:' . self::HOSTILE_RULES[mt_rand(0, count(self::HOSTILE_RULES) - 1)];
        $lines = self::linesOf($text);
        $rules = 0;
        $replaced = [];

        foreach ($lines as $line) {
            if (self::nameOf($line) === 'RRULE') {
                ++$rules;
                $replaced[] = $rule;

                continue;
            }

            $replaced[] = $line;
        }

        if ($rules === 0) {
            return self::lines(self::inserted($lines, max(1, count($lines) - 1), [$rule]));
        }

        return self::lines($replaced);
    }

    /**
     * The property name a content line begins with, which ends at the first
     * parameter or at the value (RFC 5545 §3.1, RFC 6350 §3.3).
     */
    private static function nameOf(string $line): string
    {
        return substr($line, 0, strcspn($line, ':;'));
    }

    /**
     * These lines with those put in before the given position.
     *
     * `array_splice()` would say the same thing in one call and lose the
     * promise with it: it works through a reference, so what comes back out
     * of the variable afterwards is an array as far as anything reading this
     * can tell, rather than the list of lines the next step is owed.
     *
     * @param non-empty-list<string> $lines
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private static function inserted(array $lines, int $at, array $extra): array
    {
        return [...array_slice($lines, 0, $at), ...$extra, ...array_slice($lines, $at)];
    }

    /**
     * The content lines of this text, without the break that ends the last
     * one — so that putting them back together is one rule rather than two.
     *
     * @return non-empty-list<string>
     */
    private static function linesOf(string $text): array
    {
        return explode("\r\n", rtrim($text, "\r\n"));
    }

    /**
     * @param list<string> $lines
     */
    private static function lines(array $lines): string
    {
        return implode("\r\n", $lines) . "\r\n";
    }
}
