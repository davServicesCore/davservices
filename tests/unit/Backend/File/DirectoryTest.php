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

use DavServices\Backend\File\Directory;
use DavServices\Backend\File\File;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-BE-05 (a reference backend), R-TREE-02, R-TREE-03
 * and R-TREE-06 (a backend that can copy or move in one operation is asked
 * first).
 *
 * A directory on a disc becomes a collection, and the member names are the
 * only thing that comes from outside. There are **two** rules about them, and
 * they are not the same rule.
 *
 * **What could reach outside this directory is refused when it is read.** A
 * separator, a dot name, a byte that ends a string in C: those are refused by
 * `Uri\Path` before a request ever gets this far, and refused again here,
 * because a plugin may ask a collection for a member directly and the backend
 * is the last one holding the door.
 *
 * **What no filesystem everywhere would take is refused when it is created.**
 * `CON` cannot be a file on Windows; a name ending in a space or a dot is
 * silently renamed there. Refusing those everywhere keeps a tree that was made
 * on one system usable on another — and a silent rename is a worse answer than
 * a refusal.
 */
#[CoversClass(Directory::class)]
final class DirectoryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/davservices-directory-' . bin2hex(random_bytes(8));

        if (!mkdir($root) && !is_dir($root)) {
            self::fail(sprintf('The test could not make "%s".', $root));
        }

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        self::removeEverythingIn($this->root);
    }

    public function testKnowsWhatItIsCalled(): void
    {
        self::assertSame('calendars', (new Directory($this->root, 'calendars'))->name());
    }

    public function testListsWhatIsInIt(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');
        mkdir($this->root . '/alice');

        $members = (new Directory($this->root))->children();

        usort($members, static fn ($one, $other): int => $one->name() <=> $other->name());

        self::assertSame(['alice', 'work.ics'], array_map(static fn ($node): string => $node->name(), $members));
        self::assertInstanceOf(Directory::class, $members[0] ?? null, 'A directory was not listed as a collection.');
        self::assertInstanceOf(File::class, $members[1] ?? null, 'A file was not listed as a file.');
    }

    public function testHandsOverAFileAsAFile(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');

        self::assertInstanceOf(File::class, (new Directory($this->root))->child('work.ics'));
    }

    public function testHandsOverADirectoryAsACollection(): void
    {
        mkdir($this->root . '/alice');

        self::assertInstanceOf(Directory::class, (new Directory($this->root))->child('alice'));
    }

    public function testKnowsWhatIsInIt(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');

        $directory = new Directory($this->root);

        self::assertTrue($directory->hasChild('work.ics'));
        self::assertFalse($directory->hasChild('nowhere.ics'));
    }

    public function testRefusesToHandOverWhatIsNotThere(): void
    {
        $this->expectException(NotFound::class);

        (new Directory($this->root))->child('nowhere.ics');
    }

    /**
     * The last door: `Uri\Path` refuses these long before a request gets here,
     * and a plugin that asks a collection for a member directly does not go
     * through `Uri\Path` at all.
     */
    #[DataProvider('namesThatCouldReachOutside')]
    public function testRefusesToLookUpANameThatCouldReachOutside(string $name): void
    {
        $directory = new Directory($this->root);

        self::assertFalse($directory->hasChild($name));

        $this->expectException(NotFound::class);

        $directory->child($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatCouldReachOutside(): iterable
    {
        yield 'the directory itself' => ['.'];
        yield 'the one above it' => ['..'];
        yield 'a path rather than a name' => ['alice/work.ics'];
        yield 'a path with the other separator' => ['alice\\work.ics'];
        yield 'a way out' => ['../../etc/passwd'];
        yield 'a name that ends a string in C' => ["work\0.ics"];
        yield 'no name at all' => [''];
    }

    public function testCreatesAFileFromAString(): void
    {
        (new Directory($this->root))->createFile('work.ics', 'a meeting');

        self::assertSame('a meeting', file_get_contents($this->root . '/work.ics'));
    }

    /**
     * R-TREE-03: and from a stream, without reading it into memory on the way.
     */
    public function testCreatesAFileFromAStream(): void
    {
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);
        fwrite($stream, 'a meeting');
        rewind($stream);

        (new Directory($this->root))->createFile('work.ics', $stream);

        self::assertSame('a meeting', file_get_contents($this->root . '/work.ics'));
    }

    public function testCreatesAnEmptyFileWhereThereIsNoContent(): void
    {
        (new Directory($this->root))->createFile('work.ics');

        self::assertSame('', file_get_contents($this->root . '/work.ics'));
    }

    /**
     * A `PUT` answers with the entity tag where the backend can say one, so
     * that the client need not ask again.
     */
    public function testSaysWhatItCreatedIsTaggedAs(): void
    {
        $tag = (new Directory($this->root))->createFile('work.ics', 'a meeting');

        self::assertSame((new File($this->root . '/work.ics', 'work.ics'))->etag(), $tag);
    }

    public function testRefusesToCreateOverSomethingThatIsThere(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');

        $this->expectException(Conflict::class);

        (new Directory($this->root))->createFile('work.ics', 'another meeting');
    }

    public function testCreatesACollection(): void
    {
        (new Directory($this->root))->createCollection('alice');

        self::assertDirectoryExists($this->root . '/alice');
    }

    public function testRefusesToCreateACollectionOverSomethingThatIsThere(): void
    {
        mkdir($this->root . '/alice');

        $this->expectException(Conflict::class);

        (new Directory($this->root))->createCollection('alice');
    }

    /**
     * A name that one system stores and another silently renames is a tree
     * that cannot be moved. Refusing it everywhere is the only answer that
     * behaves the same wherever the server runs.
     */
    #[DataProvider('namesNoFilesystemEverywhereWouldTake')]
    public function testRefusesToCreateANameThatWouldNotSurviveEverywhere(string $name): void
    {
        $this->expectException(Forbidden::class);

        (new Directory($this->root))->createFile($name, 'a meeting');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesNoFilesystemEverywhereWouldTake(): iterable
    {
        yield 'a device Windows keeps for itself' => ['CON'];
        yield 'the same with an extension' => ['nul.ics'];
        yield 'a serial port' => ['COM1'];
        yield 'a printer' => ['LPT3'];
        yield 'a name that would start a stream on Windows' => ['work:hidden.ics'];
        yield 'a character Windows will not take' => ['what?.ics'];
        yield 'a name that ends in a dot' => ['work.'];
        yield 'a name that ends in a space' => ['work '];
        yield 'a way out' => ['../work.ics'];
    }

    public function testRefusesToCreateACollectionOfSuchAName(): void
    {
        $this->expectException(Forbidden::class);

        (new Directory($this->root))->createCollection('CON');
    }

    /**
     * A collection goes with everything below it (R-DAV-06 from the other
     * side): the server empties it member by member, but a `COPY` over an
     * existing collection removes it in one go.
     */
    public function testGoesAwayWithEverythingInIt(): void
    {
        mkdir($this->root . '/alice');
        file_put_contents($this->root . '/alice/work.ics', 'a meeting');
        mkdir($this->root . '/alice/old');
        file_put_contents($this->root . '/alice/old/last-year.ics', 'a meeting');

        (new Directory($this->root . '/alice', 'alice'))->delete();

        self::assertDirectoryDoesNotExist($this->root . '/alice');
    }

    public function testKnowsWhenItLastChanged(): void
    {
        $changed = (new Directory($this->root))->lastModified();

        self::assertNotNull($changed);
        self::assertEqualsWithDelta(time(), $changed->getTimestamp(), 5);
    }

    /**
     * R-TREE-06: a rename is one operation where the server's own way is to
     * copy every node and then delete every node.
     */
    public function testSaysNothingAboutADirectoryThatIsNoLongerThere(): void
    {
        mkdir($this->root . '/alice');

        $alice = new Directory($this->root . '/alice', 'alice');

        rmdir($this->root . '/alice');

        self::assertNull($alice->lastModified());
    }

    public function testMovesAFileInItself(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');
        mkdir($this->root . '/archive');

        $moved = (new Directory($this->root . '/archive', 'archive'))
            ->moveInto('work.ics', 'work.ics', new File($this->root . '/work.ics', 'work.ics'));

        self::assertTrue($moved);
        self::assertFileExists($this->root . '/archive/work.ics');
        self::assertFileDoesNotExist($this->root . '/work.ics');
    }

    public function testMovesAWholeCollectionInItself(): void
    {
        mkdir($this->root . '/alice');
        file_put_contents($this->root . '/alice/work.ics', 'a meeting');
        mkdir($this->root . '/archive');

        $moved = (new Directory($this->root . '/archive', 'archive'))
            ->moveInto('alice', 'alice', new Directory($this->root . '/alice', 'alice'));

        self::assertTrue($moved);
        self::assertFileExists($this->root . '/archive/alice/work.ics');
    }

    /**
     * A node from another backend is handed back to the server: this one can
     * rename what is on its own disc and nothing else.
     */
    public function testHandsBackANodeItDidNotMake(): void
    {
        $directory = new Directory($this->root);

        self::assertFalse($directory->moveInto('work.ics', 'work.ics', new MemoryFile('work.ics', 'a meeting')));
        self::assertFalse($directory->copyInto('work.ics', 'work.ics', new MemoryFile('work.ics', 'a meeting')));
    }

    public function testCopiesAFileInItself(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');
        mkdir($this->root . '/archive');

        $copied = (new Directory($this->root . '/archive', 'archive'))
            ->copyInto('work.ics', 'work.ics', new File($this->root . '/work.ics', 'work.ics'));

        self::assertTrue($copied);
        self::assertSame('a meeting', file_get_contents($this->root . '/archive/work.ics'));
        self::assertFileExists($this->root . '/work.ics');
    }

    /**
     * A collection is handed back: copying a tree means asking every node
     * whether it may be copied, and the server does that one node at a time
     * with every listener along the way.
     */
    public function testHandsBackACollectionToCopy(): void
    {
        mkdir($this->root . '/alice');
        mkdir($this->root . '/archive');

        $copied = (new Directory($this->root . '/archive', 'archive'))
            ->copyInto('alice', 'alice', new Directory($this->root . '/alice', 'alice'));

        self::assertFalse($copied);
    }

    public function testRefusesToTakeANameItWouldNotCreate(): void
    {
        file_put_contents($this->root . '/work.ics', 'a meeting');

        $directory = new Directory($this->root);

        self::assertFalse($directory->moveInto('CON', 'work.ics', new File($this->root . '/work.ics', 'work.ics')));
        self::assertFalse($directory->copyInto('CON', 'work.ics', new File($this->root . '/work.ics', 'work.ics')));
    }

    private static function removeEverythingIn(string $directory): void
    {
        $entries = glob($directory . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            is_dir($entry) ? self::removeEverythingIn($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
