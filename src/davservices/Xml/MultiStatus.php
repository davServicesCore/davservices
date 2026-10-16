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

namespace DavServices\Xml;

use DavServices\Exception\IHttpFailure;
use DavServices\Http\Response;

/**
 * The `207 Multi-Status` of RFC 4918 §13, built in one place.
 *
 * A `207` answers a request that partly worked: a `PROPFIND` where one
 * property was missing, a `DELETE` where one member was locked. Its shape is
 * the part of WebDAV that implementations get wrong most often, so every
 * method that answers with one comes through here.
 *
 * The nesting that matters (§14.16 to §14.24): one `response` per resource,
 * each beginning with its `href`; properties grouped into a `propstat` **per
 * status**, never one block with mixed results; and a `status` of the response
 * itself where the whole resource failed rather than a property of it.
 */
final class MultiStatus
{
    /** @var list<Element> */
    private array $responses = [];

    /**
     * Adds a resource whose properties were asked for.
     *
     * @param array<int, array<string, Element|string|null>> $byStatus Properties
     *                                                                 by the status they came to, then by name as `{namespace}localname`.
     *                                                                 A value may be text, an element where the property holds XML, or
     *                                                                 null where the property is only being named.
     * @param array<string, Element|string> $errors The condition a property's
     *                                              refusal named, by property
     *                                              name, where it named one
     */
    public function addProperties(
        string $href,
        array $byStatus,
        ?string $description = null,
        array $errors = [],
    ): void {
        $this->responses[] = self::responseWith($href, $byStatus, $description, $errors);
    }

    /**
     * One `response` on its own, without a document around it.
     *
     * **RFC 3253 §3.8 nests a whole response inside a property value:**
     * `DAV:expand-property` replaces every `DAV:href` in a value with the
     * response of the resource that href names. So the shape is needed where
     * there is no multistatus to add it to — and it comes from here rather
     * than being built again by the report, because this nesting is the part
     * of WebDAV implementations get wrong most often and a second copy is a
     * second chance to get it wrong.
     *
     * @param array<int, array<string, Element|string|null>> $byStatus As for
     *                                                                 {@see self::addProperties()}
     * @param array<string, Element|string> $errors As for
     *                                              {@see self::addProperties()}
     */
    public static function responseWith(
        string $href,
        array $byStatus,
        ?string $description = null,
        array $errors = [],
    ): Element {
        $response = self::responseFor($href);

        foreach ($byStatus as $status => $properties) {
            foreach (self::propStatsFor($status, $properties, $errors) as $propStat) {
                $response->append($propStat);
            }
        }

        self::describe($response, $description);

        return $response;
    }

    /**
     * Adds a resource that fared one way as a whole.
     *
     * @param Element|string|null $error A condition of RFC 4918 §16 where one
     *                                   applies: its name where it is empty,
     *                                   the element itself where it carries
     *                                   detail of its own
     */
    public function addStatus(
        string $href,
        int $status,
        Element|string|null $error = null,
        ?string $description = null,
    ): void {
        $response = self::responseFor($href);

        $response->append(self::status($status));

        if ($error !== null) {
            $response->append(Error::of($error));
        }

        self::describe($response, $description);

        $this->responses[] = $response;
    }

    /**
     * Adds a resource that was refused, as the refusal itself describes it.
     *
     * Every method that reports a failure per path does it the same way: the
     * status, the precondition where the refusal names one, and the message
     * where there is one to read. A refusal that came without a message says
     * nothing rather than saying nothing at length.
     */
    public function addFailure(string $href, IHttpFailure $failure): void
    {
        $message = $failure->getMessage();

        $this->addStatus($href, $failure->status(), $failure->errorElement(), $message === '' ? null : $message);
    }

    /**
     * The whole document, ready for the writer.
     */
    public function toElement(): Element
    {
        $multiStatus = new Element('{DAV:}multistatus');

        foreach ($this->responses as $response) {
            $multiStatus->append($response);
        }

        return $multiStatus;
    }

    private static function responseFor(string $href): Element
    {
        $response = new Element('{DAV:}response');
        $target = new Element('{DAV:}href');

        $target->appendText($href);
        $response->append($target);

        return $response;
    }

    /**
     * The `propstat` blocks one status needs.
     *
     * **One block per condition**, because §14.22 gives a `propstat` a single
     * `error?` and RFC 4918 §16 puts the condition in "the appropriate
     * 'propstat'". A `propstat` is the properties that fared identically, so
     * two reasons are two blocks at the same status — and two properties
     * refused for the *same* reason are one block, which is what a `propstat`
     * is for. The properties that named nothing are a block like any other.
     *
     * They come out in the order the properties came in, which is the order
     * the request named them (RFC 4918 §9.2 has the instructions carried out
     * in that order).
     *
     * @param array<string, Element|string|null> $properties
     * @param array<string, Element|string> $errors
     *
     * @return list<Element>
     */
    private static function propStatsFor(int $status, array $properties, array $errors): array
    {
        /** @var list<array{condition: Element|string|null, properties: array<string, Element|string|null>}> $blocks */
        $blocks = [];

        foreach ($properties as $name => $value) {
            $condition = $errors[$name] ?? null;
            $at = self::blockOf($blocks, $condition);

            $blocks[$at]['condition'] = $condition;
            $blocks[$at]['properties'][$name] = $value;
        }

        // **A resource with nothing to say still says so** (RFC 4918 §14.24):
        // a status that carries no property at all is still one `propstat`.
        if ($blocks === []) {
            return [self::propStat($status, [])];
        }

        $propStats = [];

        foreach ($blocks as $block) {
            $propStats[] = self::propStat($status, $block['properties'], $block['condition']);
        }

        return $propStats;
    }

    /**
     * Which block this condition belongs in, adding one where it has none yet.
     *
     * **`===` is the whole of the rule**, and it says the right thing of each
     * of the three shapes by itself: two names are the same condition where
     * they read the same, two elements only where they *are* the same one, and
     * nothing matches nothing. The identity half matters because §16 lets an
     * element carry "child elements with specific error information" — two
     * refusals naming one condition with different detail inside are two
     * fates.
     *
     * @param list<array{condition: Element|string|null, properties: array<string, Element|string|null>}> $blocks
     */
    private static function blockOf(array $blocks, Element|string|null $condition): int
    {
        foreach ($blocks as $at => $block) {
            if ($block['condition'] === $condition) {
                return $at;
            }
        }

        return count($blocks);
    }

    /**
     * @param array<string, Element|string|null> $properties
     */
    private static function propStat(int $status, array $properties, Element|string|null $error = null): Element
    {
        $propStat = new Element('{DAV:}propstat');
        $prop = new Element('{DAV:}prop');

        foreach ($properties as $name => $value) {
            $prop->append(self::property($name, $value));
        }

        $propStat->append($prop);
        $propStat->append(self::status($status));

        if ($error !== null) {
            $propStat->append(Error::of($error));
        }

        return $propStat;
    }

    /**
     * A property carries XML, text, or nothing but its own name — the last of
     * which is what a `404` block and a `propname` answer are made of.
     */
    private static function property(string $name, Element|string|null $value): Element
    {
        if ($value instanceof Element) {
            return $value;
        }

        $property = new Element($name);

        if ($value !== null) {
            $property->appendText($value);
        }

        return $property;
    }

    /**
     * `HTTP/1.1 404 Not Found`. The phrase comes from the response object, so
     * that the library says the same thing in a status line and in a header.
     */
    private static function status(int $status): Element
    {
        $element = new Element('{DAV:}status');

        $element->appendText(rtrim(sprintf('HTTP/1.1 %d %s', $status, (new Response($status))->reason())));

        return $element;
    }

    private static function describe(Element $response, ?string $description): void
    {
        if ($description === null) {
            return;
        }

        $element = new Element('{DAV:}responsedescription');

        $element->appendText($description);
        $response->append($element);
    }
}
