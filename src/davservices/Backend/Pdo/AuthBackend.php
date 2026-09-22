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

use DavServices\Backend\IAuthBackend;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Credentials in a table, one row each.
 *
 * The schema ships beside this class in `credentials.sql`; this library runs
 * it for nobody, because it has no migration tool and no business deciding
 * when somebody's schema changes.
 *
 * **It is a table of its own, not a column on the principals.** The two seams
 * are apart on purpose: a principal row is read to answer `PROPFIND`, and a
 * hash is not something a client is ever told. Keeping them apart means a
 * later mistake about who may read the principals is not also a mistake about
 * credentials — and it lets a deployment put the two in different databases,
 * or leave this one out entirely and write its own against a directory.
 *
 * **`password_verify()` and nothing invented.** The hashes are whatever
 * `password_hash()` wrote, so the algorithm and cost belong to the
 * deployment.
 *
 * **The same work for a user-id nobody has**: an unknown one is verified
 * against a placeholder hash rather than returned on early, because an early
 * return answers "does this user-id exist?" in the one currency no firewall
 * filters.
 */
final class AuthBackend implements IAuthBackend
{
    /**
     * A hash to compare against where nobody was found.
     *
     * **A real hash of an unguessable value**, so that verifying it costs
     * what verifying a real one costs. A shorter placeholder, or none, would
     * make an unknown user-id the fast case — which is the whole of what this
     * is here to prevent.
     */
    private const NOBODY = '$2y$12$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi';

    /**
     * @param string $table Where the credentials are. A table cannot be a
     *                      bound parameter, so what goes here has to be
     *                      beyond doubt
     *
     * @throws InvalidArgumentException If the table name is not a plain name,
     *                                  or if the connection swallows its
     *                                  errors
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $table = 'davservices_credentials',
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is no table name.', $table));
        }

        if ($connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException(
                'The connection has to report its errors as exceptions: a query that failed silently would let nobody in and say nothing about why.',
            );
        }
    }

    /**
     * The principal these credentials name, or null.
     *
     * Every refusal is the same refusal. A user-id is a bound parameter like
     * any other, so somebody called `'; DROP TABLE` is a person with an
     * awkward name and nothing more.
     */
    public function principalFor(string $userId, string $password): ?string
    {
        $row = $this
            ->run(sprintf('SELECT password_hash, principal FROM %s WHERE user_id = ?', $this->table), [$userId])
            ->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, string|null>|false $row */
        $hash = $row === false ? null : ($row['password_hash'] ?? null);
        $principal = $row === false ? null : ($row['principal'] ?? null);

        // Verified either way, so that an unknown user-id costs what a known
        // one costs. The answer is then thrown away where there was nobody.
        $matches = password_verify($password, $hash ?? self::NOBODY);

        return $matches && $principal !== null && $principal !== '' ? $principal : null;
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
            throw new InvalidArgumentException(sprintf('The database would not prepare "%s".', $sql));
        }
        // @codeCoverageIgnoreEnd

        $statement->execute($values);

        return $statement;
    }
}
