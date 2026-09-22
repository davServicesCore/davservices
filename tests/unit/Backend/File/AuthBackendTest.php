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

namespace DavServices\Tests\Unit\Backend\File;

use DavServices\Backend\File\AuthBackend;
use DavServices\Backend\IAuthBackend;
use DavServices\Tests\Unit\Backend\AuthBackendContract;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The contract, run against credentials in a file, plus what only a file can
 * get wrong.
 *
 * **A file edited by hand grows a stray line sooner or later**, and that is
 * the whole of what this adds to the contract: a line nobody can read is
 * skipped rather than fatal. One person's typing mistake locking everybody
 * else out would be a far worse answer than one person not being able to
 * sign in — which they find out the moment they try.
 *
 * The passwords here are hashed with `password_hash()` at the cheapest cost
 * the test can use, because the contract is about answers and not about how
 * long they take.
 */
#[CoversClass(AuthBackend::class)]
final class AuthBackendTest extends AuthBackendContract
{
    private string $file = '';

    protected function setUp(): void
    {
        $file = sys_get_temp_dir() . '/davservices-credentials-' . bin2hex(random_bytes(6)) . '.txt';

        $this->file = $file;

        $this->write([
            '# A comment, which is not a person.',
            '',
            $this->line('alice', 'open sesame', 'alice'),
            $this->line('c.carter', 'hunter2', 'carol'),
            $this->line('dagmar', "123\xC2\xA3", 'dagmar'),
            $this->line('erin', 'pass:word:with:colons', 'erin'),
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * **A file this library made up is one nobody meant to have.** A missing
     * one is a deployment mistake, and inventing an empty file would turn it
     * into "nobody can sign in" with nothing to say why.
     */
    public function testAFileThatIsNotThereIsRefusedAtTheDoor(): void
    {
        $this->expectException(RuntimeException::class);

        new AuthBackend($this->file . '-not-here');
    }

    /**
     * **A line nobody can read is skipped, and the rest of the file still
     * works.** That is the trade this backend makes: the person on the broken
     * line cannot sign in, which they see at once, rather than everybody
     * being locked out by somebody else's typing.
     */
    public function testALineNobodyCanReadIsSkipped(): void
    {
        $this->write([
            'this line has no colons at all',
            'two:parts',
            '::',
            ':' . password_hash('x', PASSWORD_BCRYPT) . ':nameless',
            $this->line('alice', 'open sesame', 'alice'),
        ]);

        $backend = $this->backend();

        self::assertNull($backend->principalFor('two', 'parts'));
        self::assertSame('alice', $backend->principalFor('alice', 'open sesame'), 'and the good line still works');
    }

    /**
     * **A comment is not a person**, so a file can be annotated by whoever
     * keeps it — which is the point of a file backend at all.
     */
    public function testACommentIsNotAPerson(): void
    {
        self::assertNull($this->backend()->principalFor('# A comment, which is not a person.', ''));
    }

    /**
     * **A hash may hold colons and often does**, since `$2y$12$…` is full of
     * characters and the format is not this library's to constrain. The line
     * is split into three and no further, so everything between the first and
     * the last field is the hash.
     */
    public function testAHashIsTakenWholeHoweverItIsWritten(): void
    {
        self::assertSame('alice', $this->backend()->principalFor('alice', 'open sesame'));
    }

    /**
     * **The file is read once per instance.** Every request asks at least
     * once, and reading per question would be reading it several times for
     * one answer — but somebody added while the server runs is there for the
     * next request rather than the one after.
     */
    public function testTheFileIsReadOncePerInstance(): void
    {
        $backend = $this->backend();

        $backend->principalFor('alice', 'open sesame');

        unlink($this->file);

        self::assertSame('alice', $backend->principalFor('alice', 'open sesame'));
    }

    protected function backend(): IAuthBackend
    {
        return new AuthBackend($this->file);
    }

    private function line(string $userId, string $password, string $principal): string
    {
        return sprintf('%s:%s:%s', $userId, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $principal);
    }

    /**
     * @param list<string> $lines
     */
    private function write(array $lines): void
    {
        file_put_contents($this->file, implode("\n", $lines) . "\n");
    }
}
