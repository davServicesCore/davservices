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

namespace DavServices\Backend\File;

use DavServices\Backend\IPropertyStorageBackend;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use FilesystemIterator;
use RuntimeException;

/**
 * Dead properties in a directory, one file per path (R-PROP-02).
 *
 * The simple storage: no database, nothing to set up but a directory the web
 * server may write to. What a client invented is written back out as the XML
 * it arrived as (R-PROP-03), so that a file can be read by a person and the
 * values survive whatever the library learns to understand later.
 *
 * **The file is named after a hash of the path, and holds the path inside.**
 * A hash is a name every filesystem accepts, whatever a client called its
 * calendar — no length limit, no reserved characters, no case-insensitivity
 * to trip over. The price is that removing or moving a collection means
 * reading the directory to see which paths lie below it. That is the trade
 * this backend makes: it is the one that works everywhere, not the one that
 * scales.
 *
 *     $storage = new PropertyStorage('/var/lib/davservices/properties');
 */
final class PropertyStorage implements IPropertyStorageBackend
{
    /** The namespace of the wrapper, which is this library's own business. */
    private const STORAGE = '{https://dav.services/storage}';

    /**
     * Generous, because the request that wrote this was capped already: what
     * is read here is this library's own file, not a stranger's document.
     */
    private const MAXIMUM_BYTES = 16_777_216;

    private readonly Writer $writer;

    private readonly Reader $reader;

    /**
     * @param string $directory Where the files go; it has to exist, because
     *                          creating directories on a guess is how a typo
     *                          ends up with a properties store in a web root
     *
     * @throws RuntimeException If the directory is not there
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('"%s" is no directory.', $directory));
        }

        $this->writer = new Writer([self::namespaceOnly() => 's']);
        $this->reader = new Reader(self::MAXIMUM_BYTES);
    }

    /**
     * Out of the one file the path is kept in, or none where there is no file.
     *
     * @return list<string>
     */
    public function propertyNames(string $path): array
    {
        return array_keys($this->kept($path));
    }

    /**
     * One read of one file, whatever is asked for: the properties of a path
     * are written together, so they come back together.
     *
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    public function properties(string $path, array $names): array
    {
        $kept = $this->kept($path);
        $found = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $kept)) {
                $found[$name] = $kept[$name];
            }
        }

        return $found;
    }

    /**
     * Read, change, write: the file is replaced as a whole, so a path's
     * properties are never half changed.
     *
     * @param array<string, Element|string|null> $mutations
     */
    public function patchProperties(string $path, array $mutations): void
    {
        $kept = $this->kept($path);

        foreach ($mutations as $name => $value) {
            if ($value === null) {
                unset($kept[$name]);
            } else {
                $kept[$name] = $value;
            }
        }

        $this->keep($path, $kept);
    }

    /**
     * Reads the directory to see which paths lie below this one, because a
     * file named after a hash says nothing about where it belongs.
     */
    public function forget(string $path): void
    {
        foreach ($this->files() as $file => $kept) {
            if (Path::isBelow($kept, $path)) {
                $this->remove($file);
            }
        }
    }

    /**
     * Writes each file again under the name of the path the copy is at, and
     * leaves the original where it is.
     */
    public function copyTo(string $from, string $to): void
    {
        foreach ($this->files() as $file => $kept) {
            if (Path::isBelow($kept, $from)) {
                $this->keep($to . substr($kept, strlen($from)), $this->read($file));
            }
        }
    }

    /**
     * Writes each file again under the name of its new path, and removes the
     * old one. A rename would do where the name said something about the path;
     * a hash does not.
     */
    public function moveTo(string $from, string $to): void
    {
        foreach ($this->files() as $file => $kept) {
            if (Path::isBelow($kept, $from)) {
                $this->keep($to . substr($kept, strlen($from)), $this->read($file));
                $this->remove($file);
            }
        }
    }

    /**
     * The properties kept for a path.
     *
     * @return array<string, Element|string|null>
     */
    private function kept(string $path): array
    {
        $file = $this->fileFor($path);

        return is_file($file) ? $this->read($file) : [];
    }

    /**
     * @return array<string, Element|string|null>
     */
    private function read(string $file): array
    {
        $kept = [];

        foreach ($this->reader->parse($this->contentsOf($file))->children() as $property) {
            $name = $property->attribute('name');

            if ($name !== null) {
                // A value is either the XML a client sent or plain text; the
                // one it was is the one it comes back as (R-PROP-03).
                $kept[$name] = $property->children()[0] ?? $property->text();
            }
        }

        return $kept;
    }

    /**
     * @param array<string, Element|string|null> $properties
     */
    private function keep(string $path, array $properties): void
    {
        $file = $this->fileFor($path);

        if ($properties === []) {
            // A path with nothing kept for it keeps no file either: an empty
            // one would be read, parsed and found empty for the rest of time.
            $this->remove($file);

            return;
        }

        $this->write($file, $this->writer->write(self::document($path, $properties)));
    }

    /**
     * @param array<string, Element|string|null> $properties
     */
    private static function document(string $path, array $properties): Element
    {
        $document = new Element(self::STORAGE . 'properties', ['path' => $path]);

        foreach ($properties as $name => $value) {
            $element = new Element(self::STORAGE . 'property', ['name' => $name]);

            if ($value instanceof Element) {
                $element->append($value);
            } elseif ($value !== null) {
                $element->appendText($value);
            }

            $document->append($element);
        }

        return $document;
    }

    /**
     * Every file in the store, by the path it was written for.
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $found = [];

        foreach (new FilesystemIterator($this->directory) as $entry) {
            $file = (string) $entry;

            // Whatever else is in the directory belongs to somebody else: a
            // note an administrator left, a file of another kind altogether.
            if (str_ends_with($file, '.xml')) {
                $path = $this->pathIn($file);

                if ($path !== null) {
                    $found[$file] = $path;
                }
            }
        }

        return $found;
    }

    /**
     * The path a file was written for, or null where the file is not one of
     * ours after all.
     */
    private function pathIn(string $file): ?string
    {
        return $this->reader->parse($this->contentsOf($file))->attribute('path');
    }

    /**
     * What is in a file this store wrote.
     */
    private function contentsOf(string $file): string
    {
        $document = file_get_contents($file);

        // @codeCoverageIgnoreStart
        // The file was listed a moment ago, so a false here is a disc that has
        // gone away or a permission that changed under the request. Caught all
        // the same, because the alternative is to carry on with an empty
        // string and report that the properties are gone.
        if ($document === false) {
            throw new RuntimeException(sprintf('"%s" could not be read.', $file));
        }
        // @codeCoverageIgnoreEnd

        return $document;
    }

    /**
     * A name every filesystem takes, whatever a client called its calendar.
     */
    private function fileFor(string $path): string
    {
        return sprintf('%s/%s.xml', $this->directory, hash('sha256', $path));
    }

    /**
     * Written beside the file and moved into place, so that a write that is
     * cut short leaves the properties as they were rather than half of them.
     */
    private function write(string $file, string $document): void
    {
        $temporary = $file . '.writing';

        // @codeCoverageIgnoreStart
        // A disc that is full, a directory that is no longer writable. No test
        // can bring a filesystem into that state on every platform this runs
        // on, and a write that quietly failed would lose what a client sent.
        if (file_put_contents($temporary, $document) === false || !rename($temporary, $file)) {
            throw new RuntimeException(sprintf('"%s" could not be written.', $file));
        }
        // @codeCoverageIgnoreEnd
    }

    private function remove(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        // @codeCoverageIgnoreStart
        // Same as a failed write, and worse to ignore: a `DELETE` that reports
        // success while the properties stay behind hands them to whatever is
        // created at that path next.
        if (!unlink($file)) {
            throw new RuntimeException(sprintf('"%s" could not be removed.', $file));
        }
        // @codeCoverageIgnoreEnd
    }

    private static function namespaceOnly(): string
    {
        return trim(self::STORAGE, '{}');
    }
}
