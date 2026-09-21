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

use DateTimeImmutable;
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Write locks in a table (R-LOCK-05).
 *
 * The backend for a server that is more than one machine, where a directory
 * would have to be shared before it could be believed. A database is already
 * the thing every process agrees with, which is the whole requirement a lock
 * store has: **a lock has to outlive the process that took it**, and be seen
 * by the next one.
 *
 * **The table is not made here.** This library has no migration tool and no
 * business deciding when your schema changes; `locks.sql` beside this file is
 * the statement to run once, and the tests of this class run it, so that it
 * cannot quietly stop matching the queries.
 *
 * `ext-pdo` stays a `suggest` in the `composer.json` rather than a `require`:
 * a library that made everybody install an extension for a backend they may
 * not use would be deciding something that is not its to decide.
 */
final class LockBackend implements ILockBackend
{
    /**
     * @param PDO $connection Has to report its errors as exceptions, so that
     *                        a write which failed is an error rather than a
     *                        `false` nobody looked at. It is **asked for**
     *                        rather than set: the connection belongs to the
     *                        application, and a library that quietly changed
     *                        how it reports errors would change every other
     *                        query made on it. PHP 8 hands out connections in
     *                        this mode already, so this is a wrong setting
     *                        being named, not a step to take
     * @param string $table Where the locks go; a name only, because a table
     *                      cannot be a bound parameter and what cannot be
     *                      bound has to be beyond doubt
     *
     * @throws InvalidArgumentException If the table is not a plain name, or
     *                                  the connection swallows its errors
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $table = 'davservices_locks',
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is no table name.', $table));
        }

        if ($connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException(
                'The connection has to report its errors as exceptions: a lock that was never written would otherwise be reported as taken.',
            );
        }
    }

    /**
     * One question to the database rather than a walk: the locks on the path,
     * and the deep ones on any collection above it.
     *
     * @return list<LockInfo>
     */
    public function locksOn(string $path, DateTimeImmutable $now): array
    {
        $this->dropWhatHasRunOut($now);

        $ancestors = self::ancestorsOf($path);
        $places = implode(', ', array_fill(0, count($ancestors), '?'));

        return $this->found(
            sprintf('SELECT * FROM %s WHERE root = ? OR (deep = 1 AND root IN (%s))', $this->table, $places),
            [$path, ...$ancestors],
        );
    }

    /**
     * Which locks were taken **inside** this path: a prefix match, and the one
     * query in this class where what a client called its calendar has to be
     * kept from being read as a pattern.
     *
     * @return list<LockInfo>
     */
    public function locksBelow(string $path, DateTimeImmutable $now): array
    {
        $this->dropWhatHasRunOut($now);

        // Everything lies below the root, and there is no prefix to match
        // against: every path but the root itself is inside it.
        if ($path === '') {
            return $this->found(sprintf('SELECT * FROM %s WHERE root <> ?', $this->table), ['']);
        }

        return $this->found(
            sprintf("SELECT * FROM %s WHERE root LIKE ? ESCAPE '!'", $this->table),
            [self::escapedForLike($path) . '/%'],
        );
    }

    /**
     * Written as a removal and an insertion in one transaction, which is the
     * one upsert every database this may run on agrees about.
     *
     * The connection must not already be in a transaction of its own: PDO has
     * no nested ones, and this would rather say so than quietly join whatever
     * the application had open and commit it.
     */
    public function set(LockInfo $lock): void
    {
        $expires = $lock->expiresAt();

        $this->connection->beginTransaction();

        try {
            $this->run(sprintf('DELETE FROM %s WHERE token = ?', $this->table), [$lock->token()]);
            $this->run(
                sprintf(
                    'INSERT INTO %s (token, root, scope, deep, expires_at, owner) VALUES (?, ?, ?, ?, ?, ?)',
                    $this->table,
                ),
                [
                    $lock->token(),
                    $lock->root(),
                    $lock->scope() === LockScope::Shared ? 'shared' : 'exclusive',
                    $lock->isDeep() ? 1 : 0,
                    $expires?->getTimestamp(),
                    $this->ownerAsText($lock->owner()),
                ],
            );

            $this->connection->commit();
        } catch (RuntimeException $failure) {
            // A database that refuses the write. Rolled back so that a lock is
            // either there or not, and so that the next statement on this
            // connection does not run inside a transaction nobody opened.
            $this->connection->rollBack();

            throw $failure;
        }
    }

    /**
     * One row, by the token that names it.
     */
    public function remove(LockInfo $lock): void
    {
        $this->run(sprintf('DELETE FROM %s WHERE token = ?', $this->table), [$lock->token()]);
    }

    /**
     * R-LOCK-03: what has run out is not a lock, and it does not go on taking
     * up a row either.
     */
    private function dropWhatHasRunOut(DateTimeImmutable $now): void
    {
        $this->run(
            sprintf('DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at <= ?', $this->table),
            [$now->getTimestamp()],
        );
    }

    /**
     * The paths a deep lock could be held at to reach this one, the path
     * itself included.
     *
     * The root is among them and is spelt as nothing at all, which is why it
     * is put in by name rather than found by cutting.
     *
     * **Including the path itself is what keeps the list from ever being
     * empty**, and an empty list would be written as `IN ()` — which SQLite
     * accepts and MySQL and PostgreSQL refuse as a syntax error. Asking about
     * the root would then work here and fail where it is deployed. Nothing is
     * counted twice by it either: one row matched by two halves of an `OR` is
     * still one row.
     *
     * @return list<string>
     */
    private static function ancestorsOf(string $path): array
    {
        $ancestors = [''];
        $walked = '';

        foreach (Path::segments($path) as $segment) {
            $walked = $walked === '' ? $segment : $walked . '/' . $segment;
            $ancestors[] = $walked;
        }

        return $ancestors;
    }

    /**
     * `%` and `_` are a pattern to `LIKE` and a name to a client.
     *
     * A calendar called `100%` would otherwise match every path that begins
     * with `100`, and the lock on it would be reported for somebody else's
     * resource.
     */
    private static function escapedForLike(string $path): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $path);
    }

    /**
     * @param list<string|int|null> $values
     *
     * @return list<LockInfo>
     */
    private function found(string $sql, array $values): array
    {
        $statement = $this->run($sql, $values);
        $locks = [];

        /** @var array<string, string|int|null> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locks[] = $this->lockFrom($row);
        }

        return $locks;
    }

    /**
     * @param array<string, string|int|null> $row
     */
    private function lockFrom(array $row): LockInfo
    {
        $expires = $row['expires_at'] ?? null;
        $owner = $row['owner'] ?? null;

        return new LockInfo(
            (string) ($row['root'] ?? ''),
            (string) ($row['token'] ?? ''),
            ($row['scope'] ?? '') === 'shared' ? LockScope::Shared : LockScope::Exclusive,
            (int) ($row['deep'] ?? 0) === 1,
            $owner === null ? null : $this->ownerFromText((string) $owner),
            $expires === null ? null : new DateTimeImmutable('@' . $expires),
        );
    }

    /**
     * The owner goes into its column as the document the client sent, because
     * a column holds text and RFC 4918 §14.17 allows any XML at all.
     */
    private function ownerAsText(Element|string|null $owner): ?string
    {
        if ($owner === null) {
            return null;
        }

        $held = new Element('{DAV:}owner');

        $owner instanceof Element ? $held->append($owner) : $held->appendText($owner);

        return (new Writer())->write($held);
    }

    private function ownerFromText(string $text): Element|string
    {
        $held = (new Reader())->parse($text);

        return $held->children()[0] ?? $held->text();
    }

    /**
     * @param list<string|int|null> $values
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
