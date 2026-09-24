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

namespace DavServices\VObject\Value;

use DavServices\VObject\ParseError;

/**
 * A `data:` URI (RFC 2397), which is how vCard 4.0 carries octets.
 *
 *     dataurl   := "data:" [ mediatype ] [ ";base64" ] "," data
 *     mediatype := [ type "/" subtype ] *( ";" parameter )
 *
 * **vCard 4.0 has no `BINARY` value type** — RFC 6350 §4 lists nine and that
 * is not among them — so a photograph is a `URI`, and RFC 6350's own examples
 * are all of this shape:
 *
 *     PHOTO:data:image/jpeg;base64,MIICajCCAdOgAwIBAgICBEUwDQYJKoZIhv
 *
 * It has a class of its own because it says something neither {@see Binary}
 * nor {@see Uri} can say alone: it pairs a media type with the octets. And it
 * is its own specification, with its own defaults.
 */
final class DataUri
{
    private const SCHEME = 'data:';

    private const BASE64 = ';base64';

    private const SEPARATOR = ',';

    /**
     * RFC 2397 §2: "If <mediatype> is omitted, it defaults to
     * text/plain;charset=US-ASCII."
     */
    private const DEFAULT_MEDIA_TYPE = 'text/plain;charset=US-ASCII';

    /**
     * A `data:` URI carrying these octets under this media type.
     */
    public static function of(string $octets, string $mediaType): string
    {
        return self::SCHEME . $mediaType . self::BASE64 . self::SEPARATOR . Binary::encode($octets);
    }

    /**
     * The octets a `data:` URI carries.
     *
     * @throws ParseError If it is no `data:` URI
     */
    public static function octetsIn(string $uri): string
    {
        [$declared, $data] = self::partsOf($uri);

        if (!str_ends_with($declared, self::BASE64)) {
            // RFC 2397 §2: "Without ';base64', the data (as a sequence of
            // octets) is represented using ASCII encoding for octets inside
            // the range of safe URL characters and using the standard %xx hex
            // encoding of URLs for octets outside that range."
            return rawurldecode($data);
        }

        return Binary::decode($data);
    }

    /**
     * The media type a `data:` URI declares, or the default of RFC 2397 §2
     * where it declares none.
     *
     * Answering with an empty string instead would leave every caller to
     * invent that default for itself, and they would not all invent the same
     * one.
     *
     * @throws ParseError If it is no `data:` URI
     */
    public static function mediaTypeOf(string $uri): string
    {
        [$declared] = self::partsOf($uri);

        if (str_ends_with($declared, self::BASE64)) {
            $declared = substr($declared, 0, -strlen(self::BASE64));
        }

        return $declared === '' ? self::DEFAULT_MEDIA_TYPE : $declared;
    }

    /**
     * What a `data:` URI says before and after its comma.
     *
     * @throws ParseError If it is not one at all. A `PHOTO` that turns out to
     *                    be an `https:` URL is a perfectly good photograph —
     *                    it is simply not one this reads, and saying so beats
     *                    answering with octets nobody put there
     *
     * @return array{string, string}
     */
    private static function partsOf(string $uri): array
    {
        $comma = strpos($uri, self::SEPARATOR);

        if (!str_starts_with($uri, self::SCHEME) || $comma === false) {
            throw new ParseError(sprintf('"%s" is no data: URI.', $uri));
        }

        return [
            substr($uri, strlen(self::SCHEME), $comma - strlen(self::SCHEME)),
            substr($uri, $comma + 1),
        ];
    }
}
