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

use DavServices\Http\Request;
use XMLWriter;

/**
 * Placeholder. Xml sits above Http in the layer order, so this import is legal.
 */
final class Writer
{
    public function __construct(private readonly XMLWriter $writer, private readonly Request $request)
    {
    }

    /**
     * Serialises the request method as a single XML element.
     *
     * Scaffold behaviour, not protocol behaviour. It exists so that both
     * constructor properties are actually read: a `readonly` property that is
     * only ever written is dead weight, and PHPStan says so at level 9.
     * Replace this with the real writer API when it lands.
     */
    public function methodElement(): string
    {
        $this->writer->openMemory();
        $this->writer->startElement('method');
        $this->writer->text($this->request->method);
        $this->writer->endElement();

        return $this->writer->outputMemory();
    }
}
