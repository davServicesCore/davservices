<?php

declare(strict_types=1);

namespace DavServices\Http;

/**
 * Placeholder to exercise the quality gates on a bare scaffold.
 */
final class Request
{
    public function __construct(public readonly string $method)
    {
    }
}
