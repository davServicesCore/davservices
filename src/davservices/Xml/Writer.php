<?php

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
}
