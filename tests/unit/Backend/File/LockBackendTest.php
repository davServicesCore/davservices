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

use DavServices\Backend\File\LockBackend;
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Tests\Unit\Backend\LockBackendContract;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The contract, run against the locks kept in a directory, plus what only a
 * file store can get wrong.
 *
 * This is the backend a small deployment uses, and the reason there is no
 * in-memory one: **a lock has to outlive the process that took it.** Two
 * requests to one server are two processes as often as not, and a hold only
 * one of them can see is not a hold at all.
 */
#[CoversClass(LockBackend::class)]
final class LockBackendTest extends LockBackendContract
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-locks-' . bin2hex(random_bytes(8));

        if (!mkdir($directory) && !is_dir($directory)) {
            self::fail(sprintf('The test could not make "%s".', $directory));
        }

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');

        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * A directory this library made up is a directory nobody meant to have.
     */
    public function testRefusesADirectoryThatIsNotThere(): void
    {
        $this->expectException(RuntimeException::class);

        new LockBackend($this->directory . '/nowhere');
    }

    /**
     * **A lock kept here is a lock the next process finds.** That is the whole
     * of what this backend is for, and a test that only ever asked one object
     * would prove nothing about it.
     */
    public function testALockSurvivesIntoAnotherBackendOnTheSameDirectory(): void
    {
        $lock = $this->lock('calendars/work.ics');

        (new LockBackend($this->directory))->set($lock);

        $other = new LockBackend($this->directory);

        self::assertSame([$lock->token()], $this->tokensOf($other->locksOn('calendars/work.ics', $this->now())));
    }

    /**
     * Everything a lock is comes back: what it holds, how far, for whom, and
     * until when.
     */
    public function testEverythingAboutALockComesBack(): void
    {
        $owner = new Element('{DAV:}owner');
        $principal = new Element('{DAV:}href');

        $principal->appendText('/principals/alice');
        $owner->append($principal);

        $backend = $this->backend();

        $backend->set($this->lock('calendars', deep: true, scope: LockScope::Shared, until: '2026-09-20 13:00:00'));

        $found = $this->theOne($backend->locksOn('calendars/work.ics', $this->now()));

        self::assertSame('calendars', $found->root());
        self::assertTrue($found->isDeep());
        self::assertSame(LockScope::Shared, $found->scope());
        self::assertSame('2026-09-20 13:00:00', $found->expiresAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client wrote — a name, an
     * address, a document of its own — and it comes back as it went in, so
     * that a client can tell its own lock from somebody else's.
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
     * R-LOCK-03: a lock that has run out is not a lock, and it does not go on
     * taking up room in the store either.
     */
    public function testALockThatHasRunOutIsRemovedWhileWeAreThere(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/work.ics', until: '2026-09-20 11:00:00'));
        $backend->locksOn('calendars/work.ics', $this->now());

        self::assertSame([], glob($this->directory . '/*.xml'));
    }

    public function testDroppingALockLeavesNoFileBehind(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);
        $backend->remove($lock);

        self::assertSame([], glob($this->directory . '/*.xml'));
    }

    /**
     * What is written is XML a person can read. A lock store that can only be
     * understood by the code that wrote it cannot be looked into when a client
     * says it cannot save its work.
     *
     * **The shape is asserted, not only the two strings in it.** A namespace
     * of this library's own, a short prefix and a name that says what the
     * element is are the whole of what makes the file readable, and a test
     * that greps for the path would pass on a file nobody could make sense of.
     */
    public function testWritesSomethingAPersonCanRead(): void
    {
        $owner = new Element('{DAV:}href');

        $owner->appendText('/principals/alice');

        $this->backend()->set($this->lockOwnedBy($owner));

        $written = file_get_contents(glob($this->directory . '/*.xml')[0] ?? '');

        self::assertIsString($written);
        self::assertStringContainsString('<l:lock', $written);
        self::assertStringContainsString('xmlns:l="https://dav.services/locks"', $written);
        self::assertStringContainsString('<l:owner>', $written);
        self::assertStringContainsString('calendars/work.ics', $written);
        self::assertStringContainsString('opaquelocktoken:one', $written);
    }

    /**
     * Half a lock is not a lock. A file that lost its token would come back as
     * a hold nobody can ever release, because `UNLOCK` finds a lock by the
     * token it has not got.
     */
    public function testRefusesAFileThatHasLostHalfOfWhatALockIs(): void
    {
        file_put_contents(
            $this->directory . '/half.xml',
            '<l:lock xmlns:l="https://dav.services/locks" root="calendars/work.ics"/>',
        );

        $this->expectException(RuntimeException::class);

        $this->backend()->locksOn('calendars/work.ics', $this->now());
    }

    /**
     * Whatever else is in the directory belongs to somebody else, and reading
     * the locks must not take it along.
     */
    public function testLeavesAloneWhatIsNotItsOwn(): void
    {
        $stranger = $this->directory . '/notes.txt';

        file_put_contents($stranger, 'Nothing to do with locks.');

        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksBelow('', $this->now())));
        self::assertFileExists($stranger);
    }

    /**
     * A lock store that cannot be read is **not** treated as an empty one. A
     * property that goes missing is an inconvenience; a lock that goes missing
     * lets a write through that somebody was told could not happen.
     */
    public function testRefusesToReadAStoreThatHasBeenDamaged(): void
    {
        file_put_contents($this->directory . '/damaged.xml', '<lock');

        $this->expectException(RuntimeException::class);

        $this->backend()->locksOn('calendars/work.ics', $this->now());
    }

    /**
     * XML a parser is happy with, holding something that is not a lock: a file
     * somebody put in the directory, or one from a version that wrote it
     * differently. Silence here would report the resource as free.
     */
    public function testRefusesAFileThatIsNoLockOfThisServer(): void
    {
        file_put_contents($this->directory . '/stranger.xml', '<greeting xmlns="urn:example">Hello</greeting>');

        $this->expectException(RuntimeException::class);

        $this->backend()->locksOn('calendars/work.ics', $this->now());
    }

    protected function backend(): ILockBackend
    {
        return new LockBackend($this->directory);
    }

    private function lockOwnedBy(Element|string|null $owner): LockInfo
    {
        return new LockInfo(
            'calendars/work.ics',
            'opaquelocktoken:one',
            LockScope::Exclusive,
            false,
            $owner,
            null,
        );
    }
}
