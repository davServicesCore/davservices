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

use DateTimeImmutable;
use DavServices\VObject\Component;
use DavServices\VObject\Mode;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\Rule;
use DavServices\VObject\Serializer;
use DavServices\VObject\TimeZone\Definition;
use DavServices\VObject\Validator;
use DavServices\VObject\Value\Binary;
use DavServices\VObject\Value\DataUri;
use DavServices\VObject\VCard\Converter;
use InvalidArgumentException;

/**
 * What the fuzzer does with an input. R-QS-19.
 *
 * **A fuzzer that only reads reaches only the reader**, and half of what this
 * library does with an object happens after it has been read: the object is
 * written out again, validated, converted between vCard versions, expanded
 * into a series, read as a time zone, read as a photograph. Each of those
 * steps believes things about the object it was handed — that a `DTSTART`
 * carries a date, that a `TZOFFSETTO` carries an offset, that a `PHOTO`
 * carries bytes — and a mutated input is exactly the thing that makes one of
 * those beliefs false.
 *
 * So a target is one step, under a name, and a finding says which step it
 * was.
 *
 * ## Reading comes first, and a refusal ends the target
 *
 * Every target past the first starts by reading, and most mutated inputs do
 * not get that far. That is not a finding and not a failure of the target: a
 * refusal is the correct answer, and {@see Run} already treats it as one. The
 * step after it simply does not happen, which is why the refusals are left to
 * travel up rather than being caught here.
 */
final class Targets
{
    /**
     * How far a series is expanded before the target is satisfied.
     *
     * Enough that a rule has to do real work and the generators run past
     * their first `yield`, far short of R-RRULE-04's limit, which the
     * expansion would otherwise spend its whole budget reaching on every one
     * of thousands of inputs.
     */
    private const INSTANCES = 50;

    /**
     * The properties RFC 6350 gives the URI value type so that they can carry
     * bytes: `PHOTO` (§6.2.4), `LOGO` (§6.6.3), `SOUND` (§6.7.5) and `KEY`
     * (§6.8.1).
     *
     * @var list<string>
     */
    private const CARRYING_BYTES = ['PHOTO', 'LOGO', 'SOUND', 'KEY'];

    /**
     * The steps, by the name a finding calls them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'reading strictly',
            'reading leniently',
            'reading and writing back',
            'reading and validating',
            'reading and converting a card',
            'reading and expanding a series',
            'reading a recurrence rule',
            'reading a time zone',
            'reading a photo',
        ];
    }

    /**
     * One step, ready to be handed an input.
     *
     * @throws InvalidArgumentException If no target goes by that name
     *
     * @return callable(string): mixed
     */
    public static function of(string $target): callable
    {
        return match ($target) {
            'reading strictly' => static fn (string $input): int => count(self::objectsOf($input)),
            'reading leniently' => self::readLeniently(...),
            'reading and writing back' => self::writeBack(...),
            'reading and validating' => self::validate(...),
            'reading and converting a card' => self::convert(...),
            'reading and expanding a series' => self::expand(...),
            'reading a recurrence rule' => self::decodeRules(...),
            'reading a time zone' => self::readTimeZone(...),
            'reading a photo' => self::readPhotos(...),
            default => throw new InvalidArgumentException(
                sprintf('There is no such target as "%s".', $target),
            ),
        };
    }

    /**
     * R-VOBJ-03's lenient mode, which repairs common client mistakes instead
     * of refusing them — the mode with more to go wrong in it, since it is the
     * one that carries on.
     */
    private static function readLeniently(string $input): int
    {
        $reader = new Reader($input, Mode::Lenient);
        $objects = iterator_to_array($reader->objects(), false);

        return count($objects) + count($reader->repairs());
    }

    /**
     * Read and written out again, which is the path a `PUT` takes: the
     * serializer meets whatever the reader let through.
     */
    private static function writeBack(string $input): int
    {
        $written = 0;
        $serializer = new Serializer();

        foreach (self::objectsOf($input) as $object) {
            $written += strlen($serializer->write($object));
        }

        return $written;
    }

    /**
     * Read and checked, the validator walking an object nobody wrote by hand.
     */
    private static function validate(string $input): int
    {
        $findings = 0;
        $validator = new Validator();

        foreach (self::objectsOf($input) as $object) {
            $findings += count($validator->check($object));
        }

        return $findings;
    }

    /**
     * Read and converted through every version, which is the deepest reader
     * of a card's values there is: it takes them apart and puts them back
     * together (RFC 2426, RFC 6350 §Appendix A).
     */
    private static function convert(string $input): int
    {
        $losses = 0;

        foreach (self::objectsOf($input) as $object) {
            if ($object->name() !== 'VCARD') {
                continue;
            }

            $losses += count(Converter::toTwoOne($object)->losses())
                + count(Converter::toThreeZero($object)->losses())
                + count(Converter::toFourZero($object)->losses());
        }

        return $losses;
    }

    /**
     * Read and expanded, every series the object carries, to a bound: the
     * place R-QS-19's "keine Endlosschleife" has its real home, a recurrence
     * rule being the one thing here that computes rather than reads.
     */
    private static function expand(string $input): int
    {
        $instances = 0;

        foreach (self::objectsOf($input) as $object) {
            foreach (self::identifiersOf($object) as $uid) {
                foreach (ObjectSet::of($object, $uid)->instances() as $instance) {
                    unset($instance);

                    if (++$instances >= self::INSTANCES) {
                        return $instances;
                    }
                }
            }
        }

        return $instances;
    }

    /**
     * The rule parser on its own, fed the bytes of every `RRULE` line there
     * is — a parser of one line, which the reader hands whatever it finds
     * between a colon and a line break (RFC 5545 §3.3.10).
     */
    private static function decodeRules(string $input): int
    {
        $decoded = 0;

        foreach (explode("\r\n", $input) as $line) {
            if (!str_starts_with($line, 'RRULE:')) {
                continue;
            }

            Rule::decode(substr($line, strlen('RRULE:')));
            ++$decoded;
        }

        return $decoded;
    }

    /**
     * Read as a time zone and asked for an offset, which makes the observance
     * expansion run: a `VTIMEZONE` is a recurrence rule wearing a different
     * hat (RFC 5545 §3.6.5).
     */
    private static function readTimeZone(string $input): int
    {
        $offsets = 0;
        $moment = new DateTimeImmutable('2024-07-01 12:00:00');

        foreach (self::objectsOf($input) as $object) {
            foreach ($object->components('VTIMEZONE') as $component) {
                $zone = Definition::of($component);

                $offsets += $zone?->offsetAt($moment) ?? 0;
                $offsets += strlen($zone?->nameAt($moment) ?? '');
            }
        }

        return $offsets;
    }

    /**
     * What carries a photograph into a card, read as what it claims to be.
     *
     * **R-QS-19 asks for a third thing this library does not have.** It wants
     * fuzzing "für den iCalendar- und vCard-Parser **sowie für die
     * Bildverarbeitung**", and there is no image processing here to fuzz: no
     * dimensions are read, no pixels decoded, nothing called that does
     * either.
     *
     * That is not an omission, and the requirements settle it themselves
     * rather than leaving it to a judgement. **R-ENV-02: „`gd` und `imagick`
     * sind Anwendungsschicht und damit außerhalb des Geltungsbereichs."**
     * Image processing belongs to the embedding application, so R-QS-19's
     * third subject has no counterpart in a protocol library and will not
     * grow one.
     *
     * What this library does have is the thing that **carries** the image.
     * RFC 6350 gives `PHOTO` (§6.2.4), `LOGO` (§6.6.3), `SOUND` (§6.7.5) and
     * `KEY` (§6.8.1) the URI value type, and RFC 2397's `data:` is how the
     * bytes travel inside one. That reader is what the requirement reaches
     * here, so that is what is swept.
     *
     * **Both shapes are read**, because a library that converts between vCard
     * versions meets both: 4.0 has no `BINARY` value type and carries the
     * bytes in a `data:` URI, while 2.1 and 3.0 carry them in the value with
     * `ENCODING=b` beside it (RFC 2426 §3.1.4).
     */
    private static function readPhotos(string $input): int
    {
        $octets = 0;

        foreach (self::objectsOf($input) as $object) {
            foreach (self::CARRYING_BYTES as $name) {
                foreach ($object->properties($name) as $property) {
                    $value = $property->value();
                    $encoding = $property->parameter('ENCODING')?->value();

                    $octets += match (true) {
                        str_starts_with($value, 'data:') => strlen(DataUri::octetsIn($value))
                            + strlen(DataUri::mediaTypeOf($value)),
                        in_array($encoding, ['b', 'B', 'BASE64'], true) => strlen(Binary::decode($value)),
                        default => 0,
                    };
                }
            }
        }

        return $octets;
    }

    /**
     * Everything the strict reader makes of this input.
     *
     * @return list<Component>
     */
    private static function objectsOf(string $input): array
    {
        return iterator_to_array((new Reader($input))->objects(), false);
    }

    /**
     * The UIDs this object's components carry, each one once.
     *
     * **A UID is not an array key.** `UID:0` is a perfectly good identifier —
     * §3.8.4.7 gives it the TEXT value type, and TEXT admits a digit like any
     * other character — but PHP turns a numeric string used as a key into an
     * integer, and `array_keys()` then hands back a number where a string was
     * promised. The first finding this fuzzer produced was that one, in this
     * file: a `TypeError` from `ObjectSet::of()` on the input `UID:0`. Hence
     * the list.
     *
     * @return list<string>
     */
    private static function identifiersOf(Component $object): array
    {
        $uids = [];

        foreach ($object->components() as $component) {
            $uid = $component->property('UID')?->value();

            if ($uid !== null && $uid !== '' && !in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }
}
