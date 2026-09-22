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

use DavServices\Backend\IAuthBackend;
use DavServices\Backend\Pdo\AuthBackend;
use DavServices\Tests\Unit\Backend\AuthBackendContract;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The contract, run against credentials in a table, plus what only a database
 * can get wrong.
 *
 * **The schema is run from the file that ships with the library**, not from a
 * copy in this test. A statement nobody executes is one that quietly stops
 * matching the queries beside it, and whoever runs it next finds out from a
 * client that cannot sign in.
 */
#[CoversClass(AuthBackend::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class AuthBackendTest extends AuthBackendContract
{
    private ?PDO $connection = null;

    protected function setUp(): void
    {
        $connection = new PDO('sqlite::memory:');
        $schema = file_get_contents(dirname(__DIR__, 4) . '/src/davservices/Backend/Pdo/credentials.sql');

        self::assertIsString($schema, 'The schema that ships with the library could not be read.');

        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec($schema);

        $this->connection = $connection;

        $this->add('alice', 'open sesame', 'alice');
        $this->add('c.carter', 'hunter2', 'carol');
        $this->add('dagmar', "123\xC2\xA3", 'dagmar');
        $this->add('erin', 'pass:word:with:colons', 'erin');
    }

    /**
     * A table cannot be a bound parameter, so what goes there has to be
     * beyond doubt. An application reading its table name from a
     * configuration file it does not control should find out here.
     */
    public function testRefusesATableNameThatIsNotAPlainName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthBackend($this->connection(), 'credentials; DROP TABLE users');
    }

    /**
     * **A connection that swallows its errors would let nobody in and say
     * nothing about why.** A query that failed silently looks exactly like a
     * password that was wrong, and a deployment would chase the wrong thing
     * for a day.
     */
    public function testRefusesAConnectionThatSwallowsItsErrors(): void
    {
        $quiet = new PDO('sqlite::memory:');

        $quiet->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(InvalidArgumentException::class);

        new AuthBackend($quiet);
    }

    /**
     * **A user-id is a bound parameter like any other**, so somebody with an
     * awkward name is a person with an awkward name and nothing more.
     */
    public function testAUserIdThatLooksLikeSqlIsJustAUserId(): void
    {
        $this->add("'; DROP TABLE davservices_credentials; --", 'open sesame', 'trouble');

        self::assertSame(
            'trouble',
            $this->backend()->principalFor("'; DROP TABLE davservices_credentials; --", 'open sesame'),
        );
    }

    /**
     * **Not every driver hands back what it was given.** SQLite returns the
     * types a column was declared with, while MySQL without native prepared
     * statements hands back text for everything — so the answer has to be
     * right either way.
     */
    public function testAnswersTheSameWhereTheDriverHandsBackText(): void
    {
        $this->connection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        self::assertSame('alice', $this->backend()->principalFor('alice', 'open sesame'));
    }

    /**
     * **Every question is asked afresh**, unlike the file backend beside it:
     * a table is queried per request, so somebody whose password changed a
     * moment ago cannot sign in with the old one.
     */
    public function testAChangedPasswordTakesEffectAtOnce(): void
    {
        $backend = $this->backend();

        self::assertSame('alice', $backend->principalFor('alice', 'open sesame'));

        $statement = $this->connection()->prepare(
            'UPDATE davservices_credentials SET password_hash = ? WHERE user_id = ?',
        );

        self::assertNotFalse($statement);

        $statement->execute([password_hash('something else', PASSWORD_BCRYPT, ['cost' => 4]), 'alice']);

        self::assertNull($backend->principalFor('alice', 'open sesame'));
    }

    protected function backend(): IAuthBackend
    {
        return new AuthBackend($this->connection());
    }

    private function connection(): PDO
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private function add(string $userId, string $password, string $principal): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO davservices_credentials (user_id, password_hash, principal) VALUES (?, ?, ?)',
        );

        self::assertNotFalse($statement);

        $statement->execute([$userId, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $principal]);
    }
}
