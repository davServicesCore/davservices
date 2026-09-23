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
 *     # Somebody taken out of service, and still out of service.
 *     # bob:$2y$12$…:bob
 *     alice:$2y$12$…:alice
 *     c.carter:$2y$12$…:carol
 *
 * The user-id, the hash, and the principal name — **the last need not be the
 * same as the first.** Somebody signs in as `c.carter` and is the principal
 * `carol`; that mapping is the kind of thing a deployment has and a protocol
 * library cannot guess.
 *
 * **Exactly three fields, and no colons inside any of them.** A
 * `password_hash()` string holds none, and a principal name has no business
 * holding one; a line with a fourth colon is a mistake, and a mistake is
 * skipped rather than guessed at.
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

        // No flags: every line is trimmed below and an empty one is skipped
        // there, so asking the reader to do it as well would be asking twice
        // and proving nothing.
        $lines = file($this->file);

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
     * **A line beginning with `#` is a comment, whatever else it holds.**
     * Somebody who takes an account out of service by commenting its line
     * out has taken it out of service; a parser that looked past the `#` and
     * found three fields would hand the account straight back.
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

        // **Exactly three fields**, and a fourth colon makes the line
        // unreadable rather than quietly part of one of them. A `password_hash()`
        // string holds no colon, and a principal name has no business holding
        // one either — so a line with more of them is a mistake, and a
        // mistake is better skipped than guessed at.
        $parts = explode(':', $line);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return null;
        }

        [$userId, $hash, $principal] = $parts;

        return [$userId, $hash, $principal];
    }
}
