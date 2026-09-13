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
     */
    public function addProperties(string $href, array $byStatus, ?string $description = null): void
    {
        $response = self::responseFor($href);

        foreach ($byStatus as $status => $properties) {
            $response->append(self::propStat($status, $properties));
        }

        self::describe($response, $description);

        $this->responses[] = $response;
    }

    /**
     * Adds a resource that fared one way as a whole.
     *
     * @param string|null $error A precondition of RFC 4918 §16 as
     *                           `{namespace}localname`, where one applies
     */
    public function addStatus(string $href, int $status, ?string $error = null, ?string $description = null): void
    {
        $response = self::responseFor($href);

        $response->append(self::status($status));

        if ($error !== null) {
            $failed = new Element('{DAV:}error');
            $failed->append(new Element($error));
            $response->append($failed);
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
     * @param array<string, Element|string|null> $properties
     */
    private static function propStat(int $status, array $properties): Element
    {
        $propStat = new Element('{DAV:}propstat');
        $prop = new Element('{DAV:}prop');

        foreach ($properties as $name => $value) {
            $prop->append(self::property($name, $value));
        }

        $propStat->append($prop);
        $propStat->append(self::status($status));

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
