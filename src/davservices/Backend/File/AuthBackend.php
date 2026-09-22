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

use DavServices\Backend\IAuthBackend;
use RuntimeException;

/**
 * Credentials in a file a person can edit, one line each.
 *
 *     alice:$2y$12$…:alice
 *     c.carter:$2y$12$…:carol
 *
 * The user-id, the hash, and the principal name — **the last two need not be
 * the same as the first.** Somebody signs in as `c.carter` and is the
 * principal `carol`; that mapping is the kind of thing a deployment has and a
 * protocol library cannot guess.
 *
 * ## Why this is not in the principal file
 *
 * {@see PrincipalBackend} keeps one XML file per person, and it would have
 * been easy to hang a `<password>` element in it. **The two seams are apart
 * on purpose, and so are the files.** A principal file is read to answer
 * `PROPFIND` — it holds what a client is told about somebody. A hash is not
 * that, and putting the two in one file makes every later mistake about who
 * may read it a mistake about credentials too.
 *
 * ## What it does, and what it refuses to do
 *
 * **`password_verify()` and nothing invented.** The hashes are whatever
 * `password_hash()` wrote, so a deployment picks its own algorithm and cost
 * and this backend does not have an opinion.
 *
 * **The same work for a user-id nobody has.** An unknown one is verified
 * against a placeholder hash rather than returned on early: an early return
 * answers "does this user-id exist?" in the one currency no firewall filters.
 *
 * **The file is read once per instance**, like the principal directory next
 * to it: every request asks at least once, and reading per question would be
 * reading it several times for one answer. Somebody added while the server
 * runs is there for the next request rather than the one after.
 */
final class AuthBackend implements IAuthBackend
{
    /**
     * A hash to compare against where nobody was found.
     *
     * **It is a real hash of an unguessable value**, so that verifying it
     * costs what verifying a real one costs. A shorter placeholder, or none,
     * would make an unknown user-id the fast case — which is the whole of
     * what this is here to prevent.
     */
    private const NOBODY = '$2y$12$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi';

    /** @var array<string, array{string, string}>|null */
    private ?array $people = null;

    /**
     * @param string $file Where the credentials are; it has to exist,
     *                     because a file this library made up is one nobody
     *                     meant to have
     *
     * @throws RuntimeException If the file is not there
     */
    public function __construct(private readonly string $file)
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('"%s" is no file.', $file));
        }
    }

    /**
     * The principal these credentials name, or null.
     *
     * Every refusal is the same refusal: a wrong password, a user-id nobody
     * has, a line somebody mistyped. Telling them apart would answer a
     * question no client is entitled to ask.
     */
    public function principalFor(string $userId, string $password): ?string
    {
        [$hash, $principal] = $this->read()[$userId] ?? [self::NOBODY, null];

        // Verified either way, so that an unknown user-id costs what a known
        // one costs. The result is then thrown away where there was nobody.
        $matches = password_verify($password, $hash);

        return $matches && $principal !== null ? $principal : null;
    }

    /**
     * The file, read once and kept for this instance.
     *
     * @throws RuntimeException If it cannot be read
     *
     * @return array<string, array{string, string}>
     */
    private function read(): array
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // @codeCoverageIgnoreStart
        // The file was there a moment ago, so a false here is a disc that has
        // gone away under the request.
        if ($lines === false) {
            throw new RuntimeException(sprintf('"%s" could not be read.', $this->file));
        }
        // @codeCoverageIgnoreEnd

        $people = [];

        foreach ($lines as $line) {
            $person = self::personIn($line);

            if ($person !== null) {
                [$userId, $hash, $principal] = $person;
                $people[$userId] = [$hash, $principal];
            }
        }

        return $this->people = $people;
    }

    /**
     * One line, or null where it says nothing this backend can use.
     *
     * **A line that cannot be read is skipped rather than fatal.** A file
     * edited by hand grows a stray line sooner or later, and one person's
     * typing mistake should not lock everybody else out — the person on that
     * line simply cannot sign in, which is visible the moment they try.
     *
     * @return array{string, string, string}|null
     */
    private static function personIn(string $line): ?array
    {
        $line = trim($line);

        // A comment, or a blank line that survived the filter.
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $parts = explode(':', $line, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [$userId, $hash, $principal] = $parts;

        return $userId === '' || $hash === '' || $principal === ''
            ? null
            : [$userId, $hash, $principal];
    }
}
