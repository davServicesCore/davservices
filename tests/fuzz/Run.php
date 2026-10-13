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

use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\TooManyIterations;
use Throwable;

/**
 * One run of the fuzzer over one input, and the question of what counts as a
 * finding. R-QS-19.
 *
 * **A parser refuses malformed input all day and on purpose**, so a fuzzer
 * that counted refusals would report thousands of them and find nothing. What
 * it is looking for is the other three things the plan names — no endless
 * loop, no memory error, only defined exceptions — and this is where they are
 * told apart:
 *
 * | the parser | verdict |
 * |---|---|
 * | returns something | fine |
 * | throws {@see ParseError} | fine: a refusal, and the grammar is full of reasons for one |
 * | throws {@see TooManyIterations} | fine: R-RRULE-04's hard limit saying no |
 * | throws anything else | **a finding** — a `TypeError` is a defect, not a refusal |
 * | raises a warning, notice or deprecation | **a finding** |
 * | outlasts its time limit | **a finding**, which is how an endless loop announces itself |
 *
 * The suite runs with `failOnWarning`, so a parser that reads past the end of
 * a string fails a unit test. **The fuzzer holds the same line**, or the
 * inputs nobody wrote a test for would get a quieter treatment than the ones
 * somebody did.
 *
 * **A finding carries its input**, shortened and with its length said, and
 * with every byte that a terminal could not show written as `\xNN` — a
 * finding nobody can read costs more time than it saves.
 */
final class Run
{
    /**
     * How long one input may take before it counts as an endless loop.
     *
     * Generous on purpose: the slowest thing a parser does here is expand a
     * recurrence to R-RRULE-04's limit, which is bounded work and well under
     * a second. Anything past this is not slow but stuck.
     */
    public const SECONDS = 2.0;

    /**
     * The exceptions a parser is allowed to answer with.
     *
     * @var list<class-string<Throwable>>
     */
    private const REFUSALS = [ParseError::class, TooManyIterations::class];

    /**
     * The finding this input produced, or null where it produced none.
     *
     * @param callable(string): mixed $parse What to do with the input
     */
    public static function of(string $input, callable $parse, float $limit = self::SECONDS): ?string
    {
        $raised = null;
        $before = hrtime(true);

        // **Every diagnostic counts, including a suppressed one.** The usual
        // guard — `if ((error_reporting() & $level) === 0) return;` — was
        // written here and then measured: inside a PHPUnit test
        // `error_reporting()` is 245, a mask PHPUnit picks for itself that
        // leaves out E_WARNING, E_NOTICE, E_DEPRECATED and every E_USER_*.
        // The guard would therefore have thrown away exactly what this is
        // looking for, and only in the suite, where that is hardest to
        // notice. There is nothing to weigh against that: the only `@` in the
        // library is in `Backend\File`, which no target here reads, so the
        // guard would have protected a case that cannot arise.
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised ??= sprintf('%s (level %d)', $message, $level);

            return true;
        });

        try {
            $parse($input);
        } catch (Throwable $thrown) {
            if (!in_array($thrown::class, self::REFUSALS, true)) {
                return self::finding($input, sprintf('%s: %s', $thrown::class, $thrown->getMessage()));
            }
        } finally {
            restore_error_handler();
        }

        $took = (hrtime(true) - $before) / 1e9;

        if ($raised !== null) {
            return self::finding($input, $raised);
        }

        if ($took > $limit) {
            return self::finding($input, sprintf('took %.4f s, longer than the %.4f s it may', $took, $limit));
        }

        return null;
    }

    /**
     * One finding, with as much of its input as a reader can use.
     *
     * Two lines, the second indented by two spaces **relative to the first**:
     * whoever prints this puts it under a heading of its own, and an indent
     * measured from the left margin would land wherever that heading happened
     * to end.
     */
    private static function finding(string $input, string $what): string
    {
        return sprintf("%s\n  input (%d bytes): %s", $what, strlen($input), self::readable($input));
    }

    /**
     * An input a terminal can show: the first 120 bytes, with everything that
     * is not printable written as `\xNN`.
     */
    private static function readable(string $input): string
    {
        $readable = '';

        foreach (str_split(substr($input, 0, 120)) as $byte) {
            $readable .= ctype_print($byte) ? $byte : sprintf('\x%02x', ord($byte));
        }

        return $readable . (strlen($input) > 120 ? ' …' : '');
    }
}
