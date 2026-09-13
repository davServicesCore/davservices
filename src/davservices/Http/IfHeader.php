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

use DavServices\Exception\BadRequest;

/**
 * The WebDAV `If` header of RFC 4918 §10.4, taken apart.
 *
 * The grammar in one breath: the header is a list of lists. Every condition
 * inside a list has to hold — they are an *and*; one list holding is enough
 * for the header — they are an *or*. A condition is a state token in angle
 * brackets or an entity tag in square ones, either optionally negated by
 * `Not`. A list may be tagged with the resource it is about, and that tag
 * holds for every list that follows it until the next one.
 *
 * Two things are worth saying about what this class does not do. It does not
 * decide whether the conditions hold: that needs the locks and the resources,
 * and it belongs to the lock plugin. And it does not resolve a resource tag
 * into a path, because only the server knows where it is mounted.
 *
 * Anything it cannot read is a `400`. The header guards a write — a client
 * sends it to say "only if the state is still what I saw" — so a condition
 * that was quietly dropped could let a write through that the client took
 * pains to prevent.
 */
final class IfHeader
{
    /**
     * @param list<IfList> $lists
     */
    private function __construct(private readonly array $lists)
    {
    }

    /**
     * Reads the value of one `If` header.
     *
     * @throws BadRequest If any part of it cannot be read
     */
    public static function parse(string $value): self
    {
        return new self(self::readLists($value));
    }

    /**
     * The lists, of which one holding is enough.
     *
     * @return list<IfList>
     */
    public function lists(): array
    {
        return $this->lists;
    }

    /**
     *
     * @throws BadRequest
     *
     * @return list<IfList>
     */
    private static function readLists(string $value): array
    {
        $lists = [];
        $resource = null;
        $position = 0;

        while (self::skipWhitespace($value, $position)) {
            if ($value[$position] === '<') {
                $resource = self::readUntil($value, $position, '<', '>');

                // RFC 4918 §10.4: `Tagged-list = Resource-Tag 1*List`. A tag
                // with no list behind it says nothing, and a header that says
                // nothing cannot be the condition a client believes it has put
                // on its write.
                if (!self::skipWhitespace($value, $position) || $value[$position] !== '(') {
                    throw new BadRequest('A resource tag in the If header is followed by no list.');
                }
            }

            if ($value[$position] !== '(') {
                throw new BadRequest(
                    sprintf('The If header holds "%s" where a list was expected.', $value[$position]),
                );
            }

            $lists[] = new IfList($resource, self::readConditions($value, $position));
        }

        if ($lists === []) {
            throw new BadRequest('The If header names no condition at all.');
        }

        return $lists;
    }

    /**
     *
     * @throws BadRequest
     *
     * @return list<IfCondition>
     */
    private static function readConditions(string $value, int &$position): array
    {
        $position++;
        $conditions = [];

        while (self::skipWhitespace($value, $position) && $value[$position] !== ')') {
            $conditions[] = self::readCondition($value, $position);
        }

        if (!isset($value[$position])) {
            throw new BadRequest('A list in the If header is never closed.');
        }

        $position++;

        if ($conditions === []) {
            throw new BadRequest('A list in the If header holds no condition.');
        }

        return $conditions;
    }

    /**
     * @throws BadRequest
     */
    private static function readCondition(string $value, int &$position): IfCondition
    {
        $negated = self::readNegation($value, $position);

        if (!self::skipWhitespace($value, $position)) {
            throw new BadRequest('The If header ends where a condition was expected.');
        }

        if ($value[$position] === '<') {
            return IfCondition::onStateToken(self::readUntil($value, $position, '<', '>'), $negated);
        }

        if ($value[$position] !== '[') {
            throw new BadRequest(sprintf('The If header holds "%s" where a condition was expected.', $value[$position]));
        }

        $etag = ETag::parse(self::readUntil($value, $position, '[', ']'));

        if ($etag === null) {
            throw new BadRequest('A condition in the If header holds something that is no entity tag.');
        }

        return IfCondition::onETag($etag, $negated);
    }

    /**
     * RFC 5234 §2.3 makes the literals of an ABNF grammar case-insensitive,
     * so `Not`, `not` and `NOT` are one and the same word.
     */
    private static function readNegation(string $value, int &$position): bool
    {
        if (strtoupper(substr($value, $position, 3)) !== 'NOT') {
            return false;
        }

        $position += 3;

        return true;
    }

    /**
     * The text between a pair of delimiters, leaving the position after the
     * closing one.
     *
     * @throws BadRequest If it is never closed, or holds nothing
     */
    private static function readUntil(string $value, int &$position, string $opening, string $closing): string
    {
        $end = strpos($value, $closing, $position + 1);

        if ($end === false) {
            throw new BadRequest(sprintf('A "%s" in the If header is never closed by a "%s".', $opening, $closing));
        }

        $content = substr($value, $position + 1, $end - $position - 1);
        $position = $end + 1;

        if ($content === '') {
            throw new BadRequest(sprintf('A "%s%s" in the If header holds nothing.', $opening, $closing));
        }

        return $content;
    }

    /**
     * Moves past any whitespace; false once the end of the header is reached.
     */
    private static function skipWhitespace(string $value, int &$position): bool
    {
        $position += strspn($value, " \t", $position);

        return isset($value[$position]);
    }
}
