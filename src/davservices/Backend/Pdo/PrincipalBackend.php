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

namespace DavServices\Backend\Pdo;

use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * The people in a table (R-BE-05).
 *
 * The backend for a deployment that already keeps its users in a database and
 * would rather not keep them twice. **Nothing is written through it** —
 * {@see IPrincipalBackend} has no write methods — so the table belongs to
 * whoever fills it, and this only ever reads.
 *
 * **The table is not made here.** This library has no migration tool and no
 * business deciding when your schema changes; `principals.sql` beside this
 * file is the statement to run once, and the tests of this class run it, so
 * that it cannot quietly stop matching the queries.
 *
 * `ext-pdo` stays a `suggest` in the `composer.json` rather than a `require`:
 * a library that made everybody install an extension for a backend they may
 * not use would be deciding something that is not its to decide.
 */
final class PrincipalBackend implements IPrincipalBackend
{
    /**
     * @param PDO $connection Has to report its errors as exceptions, so that
     *                        a query which failed is an error rather than a
     *                        `false` nobody looked at. It is **asked for**
     *                        rather than set: the connection belongs to the
     *                        application, and a library that quietly changed
     *                        how it reports errors would change every other
     *                        query made on it
     * @param string $table Where the people are; a name only, because a table
     *                      cannot be a bound parameter and what cannot be
     *                      bound has to be beyond doubt
     *
     * @throws InvalidArgumentException If the table is not a plain name, or
     *                                  the connection swallows its errors
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $table = 'davservices_principals',
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is no table name.', $table));
        }

        if ($connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException(
                'The connection has to report its errors as exceptions: a query that failed silently would be an empty answer about who exists.',
            );
        }
    }

    /**
     * One principal by name, or null where nobody of that name is there.
     *
     * A name is a bound parameter like any other, so a person called `100%`
     * or `'; DROP TABLE` is a person with an awkward name and nothing more.
     */
    public function principal(string $name): ?PrincipalInfo
    {
        $row = $this
            ->run(sprintf('SELECT * FROM %s WHERE name = ?', $this->table), [$name])
            ->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, string|null>|false $row */
        return $row === false ? null : self::principalFrom($row);
    }

    /**
     * Everybody in the table, by name.
     *
     * The order is asked for rather than left to the database: a listing that
     * came back differently each time would be a collection whose members
     * moved about under a client.
     *
     * @return list<PrincipalInfo>
     */
    public function principals(): array
    {
        $found = [];

        /** @var array<string, string|null> $row */
        foreach ($this->run(sprintf('SELECT * FROM %s ORDER BY name', $this->table), [])->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[] = self::principalFrom($row);
        }

        return $found;
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function principalFrom(array $row): PrincipalInfo
    {
        $members = $row['group_members'] ?? null;

        return new PrincipalInfo(
            $row['name'] ?? '',
            $row['display_name'] ?? null,
            self::urisIn($row['alternate_uris'] ?? null),
            self::urisIn($row['group_membership'] ?? null),
            // **Null is not the empty column.** RFC 3744 §4.3 is the one
            // property of §4 a server need not support, so a NULL here says
            // "this server does not say who is in that group" while an empty
            // column says "nobody, yet". A backend collapsing the two would
            // tell every client that every group it declines to describe is
            // empty.
            $members === null ? null : self::urisIn($members),
        );
    }

    /**
     * One value per line, which is what these columns hold.
     *
     * Blank lines are skipped rather than handed over: a column somebody
     * edited by hand ends with a newline more often than not, and an empty
     * `DAV:href` in a principal's answer is a URI a client would try.
     *
     * @return list<string>
     */
    private static function urisIn(?string $lines): array
    {
        $uris = [];

        foreach (explode("\n", $lines ?? '') as $line) {
            $uri = trim($line);

            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * @param list<string> $values
     */
    private function run(string $sql, array $values): PDOStatement
    {
        $statement = $this->connection->prepare($sql);

        // @codeCoverageIgnoreStart
        // The connection is set to throw, so a false here cannot happen; the
        // guard is what makes the type honest rather than what catches a case.
        if ($statement === false) {
            throw new RuntimeException(sprintf('The database would not prepare "%s".', $sql));
        }
        // @codeCoverageIgnoreEnd

        $statement->execute($values);

        return $statement;
    }
}
