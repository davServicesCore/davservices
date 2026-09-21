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

namespace DavServices\Tests\Unit\Backend\Pdo;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\Backend\ILockBackend;
use DavServices\Backend\Pdo\LockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Tests\Unit\Backend\LockBackendContract;
use DavServices\Xml\Element;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

/**
 * The contract, run against the locks kept in a table, plus what only a
 * database can get wrong.
 *
 * **The schema is run from the file that ships with the library**, not from a
 * copy in this test. A statement nobody executes is a statement that quietly
 * stops matching the queries beside it, and whoever runs it next finds out
 * from a client that cannot save its work.
 *
 * SQLite in memory is what these run against: it is a database every PHP has
 * and needs no server, and the queries here are the ones every database
 * agrees about.
 */
#[CoversClass(LockBackend::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class LockBackendTest extends LockBackendContract
{
    private ?PDO $connection = null;

    protected function setUp(): void
    {
        $connection = new PDO('sqlite::memory:');
        $schema = file_get_contents(dirname(__DIR__, 4) . '/src/davservices/Backend/Pdo/locks.sql');

        self::assertIsString($schema, 'The schema that ships with the library could not be read.');

        $connection->exec($schema);

        $this->connection = $connection;
    }

    /**
     * A table cannot be a bound parameter, so what goes there has to be beyond
     * doubt. An application that reads its table name from a configuration
     * file it does not control should find out here rather than later.
     */
    public function testRefusesATableNameThatIsNotAPlainName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockBackend($this->connection(), 'locks; DROP TABLE davservices_locks');
    }

    public function testKeepsItsLocksInTheTableItWasGiven(): void
    {
        $this->connection()->exec('CREATE TABLE other_locks AS SELECT * FROM davservices_locks');

        $backend = new LockBackend($this->connection(), 'other_locks');
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksOn('calendars/work.ics', $this->now())));
        self::assertSame([], $this->backend()->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * **`%` and `_` are a pattern to the database and a name to a client.** A
     * calendar called `100%` would otherwise match every path beginning with
     * `100`, and its lock would be reported for somebody else's resource.
     */
    public function testAPathThatLooksLikeAPatternIsAPathAllTheSame(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/100x/work.ics'));

        self::assertSame([], $backend->locksBelow('calendars/100%', $this->now()));
        self::assertSame([], $backend->locksBelow('calendars/10_x', $this->now()));
    }

    public function testFindsWhatLiesBelowAPathThatHoldsSuchCharacters(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/100%/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksBelow('calendars/100%', $this->now())));
    }

    /**
     * Everything a lock is survives the row: what it holds, how far, for whom,
     * and until when.
     */
    public function testEverythingAboutALockComesBack(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars', deep: true, scope: LockScope::Shared, until: '2026-09-20 13:00:00'));

        $found = $this->theOne($backend->locksOn('calendars/work.ics', $this->now()));

        self::assertSame('calendars', $found->root());
        self::assertTrue($found->isDeep());
        self::assertSame(LockScope::Shared, $found->scope());
        self::assertSame('2026-09-20 13:00:00', $found->expiresAt()?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client wrote, and it comes
     * back as it went in — out of a text column, which is why it goes in as
     * the document it is.
     */
    public function testAnOwnerOfXmlComesBackAsItWentIn(): void
    {
        $owner = new Element('{DAV:}href');

        $owner->appendText('/principals/alice');

        $backend = $this->backend();

        $backend->set($this->lockOwnedBy($owner));

        $found = $this->theOne($backend->locksOn('calendars/work.ics', $this->now()))->owner();

        self::assertInstanceOf(Element::class, $found);
        self::assertSame('{DAV:}href', $found->name());
        self::assertSame('/principals/alice', $found->text());
    }

    public function testAnOwnerOfPlainTextComesBackAsText(): void
    {
        $backend = $this->backend();

        $backend->set($this->lockOwnedBy('Alice'));

        self::assertSame('Alice', $this->theOne($backend->locksOn('calendars/work.ics', $this->now()))->owner());
    }

    /**
     * R-LOCK-03: what has run out is not a lock, and it does not go on taking
     * up a row either.
     */
    public function testALockThatHasRunOutIsRemovedWhileWeAreThere(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/work.ics', until: '2026-09-20 11:00:00'));
        $backend->locksOn('calendars/work.ics', $this->now());

        self::assertSame(0, $this->rowsHeld());
    }

    /**
     * **A write that did not happen is an error, not a lock.** The obvious way
     * to reach one is a database nobody ran `locks.sql` on, and what matters
     * as much as the exception is that the transaction is not left open behind
     * it: the next statement on that connection would sit inside a transaction
     * it knows nothing about.
     */
    public function testAWriteThatFailsIsReportedAndLeavesNoTransactionOpen(): void
    {
        $empty = new PDO('sqlite::memory:');
        $backend = new LockBackend($empty);

        try {
            $backend->set($this->lock('calendars/work.ics'));

            self::fail('The write went through on a database that has no table.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('davservices_locks', $failure->getMessage());
        }

        self::assertFalse($empty->inTransaction());
    }

    /**
     * **Not every driver hands back what it was given.** SQLite returns an
     * integer column as an integer; MySQL without native prepared statements
     * returns everything as text, and `'1' === 1` is false. A deep lock read
     * back as a shallow one would let a write through into a locked
     * collection — on the database somebody deploys on, not on the one these
     * tests run against.
     *
     * `ATTR_STRINGIFY_FETCHES` is the closest this can get to that database
     * without asking for a server: it makes SQLite answer the way MySQL does.
     */
    public function testReadsALockBackFromADriverThatHandsEverythingOverAsText(): void
    {
        $this->connection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $backend = $this->backend();

        $backend->set($this->lock('calendars', deep: true, scope: LockScope::Shared, until: '2026-09-20 13:00:00'));

        $found = $this->theOne($backend->locksOn('calendars/work.ics', $this->now()));

        self::assertTrue($found->isDeep());
        self::assertSame('calendars', $found->root());
        self::assertSame('opaquelocktoken:one', $found->token());
        self::assertSame(LockScope::Shared, $found->scope());
        self::assertSame(
            (new DateTimeImmutable('2026-09-20 13:00:00'))->getTimestamp(),
            $found->expiresAt()?->getTimestamp(),
        );
    }

    /**
     * **A write that failed silently would be reported as a lock that was
     * taken**, and the client would be told its file is held when it is not.
     * The mode is asked for rather than set: the connection belongs to the
     * application, and every other query it makes on it would change with it.
     */
    public function testRefusesAConnectionThatSwallowsItsErrors(): void
    {
        $silent = new PDO('sqlite::memory:');

        $silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(InvalidArgumentException::class);

        new LockBackend($silent);
    }

    protected function backend(): ILockBackend
    {
        return new LockBackend($this->connection());
    }

    private function connection(): PDO
    {
        $connection = $this->connection;

        if ($connection === null) {
            self::fail('The test has no database.');
        }

        return $connection;
    }

    private function rowsHeld(): int
    {
        $counted = $this->connection()->query('SELECT COUNT(*) FROM davservices_locks');

        self::assertInstanceOf(PDOStatement::class, $counted);

        return (int) $counted->fetchColumn();
    }

    private function lockOwnedBy(Element|string|null $owner): LockInfo
    {
        return new LockInfo('calendars/work.ics', 'opaquelocktoken:one', LockScope::Exclusive, false, $owner, null);
    }
}
