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

use DavServices\Backend\IPrincipalBackend;
use DavServices\Backend\Pdo\PrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Tests\Unit\Backend\PrincipalBackendContract;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The contract, run against the people kept in a table, plus what only a
 * database can get wrong.
 *
 * **The schema is run from the file that ships with the library**, not from a
 * copy in this test. A statement nobody executes is a statement that quietly
 * stops matching the queries beside it, and whoever runs it next finds out
 * from a client that cannot sign in.
 *
 * SQLite in memory is what these run against: it is a database every PHP has
 * and needs no server. Where its typing hides something — it hands back the
 * types a column was declared with, while MySQL without native prepared
 * statements hands back text — `ATTR_STRINGIFY_FETCHES` is used to make it
 * answer the way the other does.
 */
#[CoversClass(PrincipalBackend::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class PrincipalBackendTest extends PrincipalBackendContract
{
    private ?PDO $connection = null;

    protected function setUp(): void
    {
        $connection = new PDO('sqlite::memory:');
        $schema = file_get_contents(dirname(__DIR__, 4) . '/src/davservices/Backend/Pdo/principals.sql');

        self::assertIsString($schema, 'The schema that ships with the library could not be read.');

        $connection->exec($schema);

        $this->connection = $connection;

        $this->add('alice', 'Alice Ashton', 'mailto:alice@example.test');
        $this->add('carol', 'Carol Carter', "mailto:carol@example.test
mailto:c.carter@example.test");
        $this->add('plain', null, null);
    }

    /**
     * A table cannot be a bound parameter, so what goes there has to be
     * beyond doubt. An application that reads its table name from a
     * configuration file it does not control should find out here.
     */
    public function testRefusesATableNameThatIsNotAPlainName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PrincipalBackend($this->connection(), 'principals; DROP TABLE davservices_principals');
    }

    /**
     * **A query that failed silently would be an empty answer about who
     * exists** — which reads as "no such person" and locks everybody out.
     * The mode is asked for rather than set: the connection belongs to the
     * application, and every other query it makes would change with it.
     */
    public function testRefusesAConnectionThatSwallowsItsErrors(): void
    {
        $silent = new PDO('sqlite::memory:');

        $silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(InvalidArgumentException::class);

        new PrincipalBackend($silent);
    }

    public function testKeepsItsPeopleInTheTableItWasGiven(): void
    {
        $this->connection()->exec('CREATE TABLE other_principals AS SELECT * FROM davservices_principals');
        $this->connection()->exec("DELETE FROM davservices_principals WHERE name = 'alice'");

        $backend = new PrincipalBackend($this->connection(), 'other_principals');

        self::assertNotNull($backend->principal('alice'));
        self::assertNull($this->backend()->principal('alice'));
    }

    /**
     * **A name is a bound parameter like any other.** Somebody called `100%`
     * or `'; DROP TABLE` is somebody with an awkward name and nothing more.
     */
    public function testANameThatLooksLikeSqlIsJustAName(): void
    {
        $this->add("'; DROP TABLE davservices_principals; --", 'Little Bobby', null);

        self::assertSame(
            'Little Bobby',
            $this->backend()->principal("'; DROP TABLE davservices_principals; --")?->displayName(),
        );
    }

    /**
     * **A column somebody edited by hand ends with a newline more often than
     * not**, and an empty `DAV:href` in a principal's answer is a URI a
     * client would try.
     */
    public function testSkipsTheBlankLinesOfAColumnSomebodyEdited(): void
    {
        $this->add('dave', null, "\nmailto:dave@example.test\n\n");

        self::assertSame(['mailto:dave@example.test'], $this->backend()->principal('dave')?->alternateUris());
    }

    /**
     * **A column edited on Windows has `\r\n`**, and a URI that carried the
     * carriage return with it would be a `mailto:` no client can use — the
     * address would end in an invisible character.
     */
    public function testTakesTheLineEndingsOfWhoeverEditedTheColumn(): void
    {
        $this->add('erin', null, "mailto:erin@example.test\r\nmailto:e.evans@example.test\r\n");

        self::assertSame(
            ['mailto:erin@example.test', 'mailto:e.evans@example.test'],
            $this->backend()->principal('erin')?->alternateUris(),
        );
    }

    /**
     * The listing is asked for in order rather than left to the database: a
     * collection whose members moved about between requests would be a
     * collection a client cannot page through.
     */
    public function testListsThemByName(): void
    {
        $this->add('bob', null, null);

        $names = array_map(
            static fn (PrincipalInfo $principal): string => $principal->name(),
            $this->backend()->principals(),
        );

        self::assertSame(['alice', 'bob', 'carol', 'plain'], $names);
    }

    /**
     * **Not every driver hands back what it was given.** SQLite returns a
     * column as the type it was declared with; MySQL without native prepared
     * statements returns everything as text, and a `null` that arrived as an
     * empty string is a display name that is there but says nothing.
     */
    public function testReadsAPersonBackFromADriverThatHandsEverythingOverAsText(): void
    {
        $this->connection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $alice = $this->backend()->principal('alice');

        self::assertNotNull($alice);
        self::assertSame('Alice Ashton', $alice->displayName());
        self::assertSame(['mailto:alice@example.test'], $alice->alternateUris());
        self::assertNull($this->backend()->principal('plain')?->displayName());
    }

    protected function backend(): IPrincipalBackend
    {
        return new PrincipalBackend($this->connection());
    }

    private function connection(): PDO
    {
        return $this->connection ?? self::fail('The test has no database.');
    }

    private function add(string $name, ?string $displayName, ?string $uris): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO davservices_principals (name, display_name, alternate_uris) VALUES (?, ?, ?)',
        );

        self::assertNotFalse($statement);

        $statement->execute([$name, $displayName, $uris]);
    }
}
