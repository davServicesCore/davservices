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

use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use FilesystemIterator;
use RuntimeException;

/**
 * The people in a directory, one file per principal (R-BE-05).
 *
 * **This one is read and never written, and that turns the design of the
 * other file backends around.** {@see IPrincipalBackend} has no write
 * methods: whoever exists comes from somewhere else, and here that somewhere
 * is a person with a text editor. The lock and property stores name their
 * files after a hash, because nothing but the library ever looks at them —
 * a file nobody can find is no trouble when nobody goes looking. Here
 * somebody does, so the files keep whatever names they were given.
 *
 * **The name inside the file is the one that counts.** The filename is not
 * read at all, which is what keeps a principal from being named `../../etc`
 * and reaching out of the directory: a name that arrives as content cannot be
 * a path.
 *
 *     <principal xmlns="https://dav.services/principals" name="alice">
 *         <display-name>Alice Ashton</display-name>
 *         <alternate-uri>mailto:alice@example.test</alternate-uri>
 *     </principal>
 *
 * **The directory is read once.** Every request asks about at least one
 * principal and a listing asks about all of them, so reading it per question
 * would be reading it several times for one answer. It is read per instance
 * and not cached beyond that, because a principal added while the server runs
 * should be there for the next request and not the one after.
 */
final class PrincipalBackend implements IPrincipalBackend
{
    /** The namespace of the file, which is this library's own business. */
    private const PRINCIPALS = '{https://dav.services/principals}';

    private readonly Reader $reader;

    /** @var array<string, PrincipalInfo>|null */
    private ?array $principals = null;

    /**
     * @param string $directory Where the files are; it has to exist, because
     *                          a directory this library made up is one nobody
     *                          meant to have
     *
     * @throws RuntimeException If the directory is not there
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('"%s" is no directory.', $directory));
        }

        $this->reader = new Reader();
    }

    /**
     * One principal by name, or null where nobody of that name is here.
     */
    public function principal(string $name): ?PrincipalInfo
    {
        return $this->read()[$name] ?? null;
    }

    /**
     * Everybody in the directory, in the order the filesystem hands them
     * over.
     *
     * @return list<PrincipalInfo>
     */
    public function principals(): array
    {
        return array_values($this->read());
    }

    /**
     * The directory, read once.
     *
     * **A file that cannot be read as a principal stops the request.** Where
     * the property storage skips a line somebody edited wrongly, this
     * refuses: a property that goes missing is an inconvenience, and a
     * principal that goes missing is a person who cannot sign in — or worse,
     * whose access control entries quietly stop matching anybody.
     *
     * @throws RuntimeException If a file is no principal this server can read
     *
     * @return array<string, PrincipalInfo>
     */
    private function read(): array
    {
        if ($this->principals !== null) {
            return $this->principals;
        }

        $found = [];

        foreach (new FilesystemIterator($this->directory) as $entry) {
            $file = (string) $entry;

            // Whatever else is in the directory belongs to somebody else.
            if (str_ends_with($file, '.xml')) {
                $principal = $this->principalIn($file);

                $found[$principal->name()] = $principal;
            }
        }

        return $this->principals = $found;
    }

    /**
     * @throws RuntimeException If the file cannot be read as a principal
     */
    private function principalIn(string $file): PrincipalInfo
    {
        $document = file_get_contents($file);

        // @codeCoverageIgnoreStart
        // The file was listed a moment ago, so a false here is a disc that
        // has gone away under the request.
        if ($document === false) {
            throw new RuntimeException(sprintf('"%s" could not be read.', $file));
        }
        // @codeCoverageIgnoreEnd

        $element = $this->reader->parse($document);
        $name = $element->attribute('name');

        if ($element->name() !== self::PRINCIPALS . 'principal' || $name === null || $name === '') {
            throw new RuntimeException(sprintf('"%s" is no principal this server wrote.', $file));
        }

        return new PrincipalInfo($name, self::displayNameIn($element), self::alternateUrisIn($element));
    }

    /**
     * What the person is called, or nothing where nobody has said. **Not the
     * name**: a login is not a name a person chose, and showing one as though
     * somebody had is how `a.mueller` ends up on a shared calendar.
     */
    private static function displayNameIn(Element $principal): ?string
    {
        foreach ($principal->children() as $child) {
            if ($child->name() === self::PRINCIPALS . 'display-name') {
                return $child->text();
            }
        }

        return null;
    }

    /**
     * The other addresses the same actor answers to (RFC 3744 §4.1), in the
     * order they were written.
     *
     * @return list<string>
     */
    private static function alternateUrisIn(Element $principal): array
    {
        $uris = [];

        foreach ($principal->children() as $child) {
            if ($child->name() === self::PRINCIPALS . 'alternate-uri') {
                $uris[] = $child->text();
            }
        }

        return $uris;
    }
}
