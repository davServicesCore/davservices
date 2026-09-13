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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\IPropertyStorageBackend;
use DavServices\Xml\Element;

/**
 * A property storage that lives in an array.
 *
 * The second implementation of the contract, which is what makes the contract
 * worth writing: two of them behaving the same is the whole promise of R-PROP-02.
 * The tests of the plugin use it as well, so that they say something about the
 * plugin rather than about a filesystem.
 */
final class MemoryPropertyStorage implements IPropertyStorageBackend
{
    /** @var array<string, array<string, Element|string|null>> */
    private array $kept = [];

    public function propertyNames(string $path): array
    {
        return array_keys($this->kept[$path] ?? []);
    }

    public function properties(string $path, array $names): array
    {
        $kept = $this->kept[$path] ?? [];
        $found = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $kept)) {
                $found[$name] = $kept[$name];
            }
        }

        return $found;
    }

    public function patchProperties(string $path, array $mutations): void
    {
        foreach ($mutations as $name => $value) {
            if ($value === null) {
                unset($this->kept[$path][$name]);
            } else {
                $this->kept[$path][$name] = $value;
            }
        }

        if (($this->kept[$path] ?? []) === []) {
            unset($this->kept[$path]);
        }
    }

    public function forget(string $path): void
    {
        foreach (array_keys($this->kept) as $kept) {
            if (self::isBelow($kept, $path)) {
                unset($this->kept[$kept]);
            }
        }
    }

    public function moveTo(string $from, string $to): void
    {
        foreach ($this->kept as $kept => $properties) {
            if (!self::isBelow($kept, $from)) {
                continue;
            }

            $this->kept[$to . substr($kept, strlen($from))] = $properties;
            unset($this->kept[$kept]);
        }
    }

    /**
     * The slash matters: `alice2` does not lie below `alice`.
     */
    private static function isBelow(string $path, string $prefix): bool
    {
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
