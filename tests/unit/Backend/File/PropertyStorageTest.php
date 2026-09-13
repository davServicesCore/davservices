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

use DavServices\Backend\File\PropertyStorage;
use DavServices\Backend\IPropertyStorageBackend;
use DavServices\Tests\Unit\Backend\PropertyStorageContract;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The contract, run against the storage that keeps its properties in files,
 * plus what only a file storage can get wrong.
 */
#[CoversClass(PropertyStorage::class)]
final class PropertyStorageTest extends PropertyStorageContract
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-properties-' . bin2hex(random_bytes(8));

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
     * The application says where its data goes, and says it once.
     */
    public function testRefusesADirectoryThatIsNotThere(): void
    {
        $this->expectException(RuntimeException::class);

        new PropertyStorage($this->directory . '/nowhere');
    }

    /**
     * The name of a file is a hash, so that a calendar called `c:\x` or one
     * whose name is three hundred characters long is stored like any other.
     */
    public function testKeepsAPathNoFilesystemWouldTakeAsAName(): void
    {
        $storage = $this->storage();
        $path = 'calendars/' . str_repeat('a', 300) . ':?*<>|.ics';

        $storage->patchProperties($path, ['{DAV:}displayname' => 'Work']);

        self::assertSame(['{DAV:}displayname' => 'Work'], $storage->properties($path, ['{DAV:}displayname']));
    }

    /**
     * A path whose properties have all been removed keeps no file behind. An
     * empty one would be read, parsed and found empty for the rest of time.
     */
    public function testLeavesNoFileBehindForAPathWithNothingKept(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => null]);

        self::assertSame([], glob($this->directory . '/*.xml'));
    }

    /**
     * What is written is XML a person can read, which is half the point of the
     * simple backend: a store that can only be understood by the code that
     * wrote it cannot be looked into when something goes wrong.
     */
    public function testWritesSomethingAPersonCanRead(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);

        $written = file_get_contents(glob($this->directory . '/*.xml')[0] ?? '');

        self::assertIsString($written);
        self::assertStringContainsString('<s:properties', $written);
        self::assertStringContainsString('<s:property ', $written);
        self::assertStringContainsString('calendars/work.ics', $written);
        self::assertStringContainsString('{DAV:}displayname', $written);
        self::assertStringContainsString('Work', $written);
    }

    /**
     * Whatever else is in the directory belongs to somebody else — a note an
     * administrator left, a file of another kind altogether — and removing a
     * collection must not take it along.
     */
    public function testLeavesAloneWhatIsNotItsOwn(): void
    {
        $storage = $this->storage();
        $stranger = $this->directory . '/notes.txt';
        $foreign = $this->directory . '/somebody-elses.xml';

        file_put_contents($stranger, 'Nothing to do with properties.');
        file_put_contents($foreign, '<thing xmlns="http://example.com/ns"/>');

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);
        $storage->forget('');

        self::assertFileExists($stranger);
        self::assertFileExists($foreign);
    }

    /**
     * The store can be read by a person, so it can be edited by one too. A
     * property that lost its name in the process is skipped rather than taking
     * every other property of that path down with it.
     */
    public function testSkipsAPropertyThatSomebodyEditedTheNameOutOf(): void
    {
        $storage = $this->storage();

        file_put_contents($this->directory . '/edited.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <s:properties xmlns:s="https://dav.services/storage" path="calendars/work.ics">
                <s:property name="{DAV:}displayname">Work</s:property>
                <s:property>The name went missing</s:property>
            </s:properties>
            XML);

        $storage->moveTo('calendars/work.ics', 'archive/work.ics');

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive/work.ics'));
    }

    /**
     * Written beside the file and moved into place, so that a write cut short
     * leaves the properties as they were rather than half of them.
     */
    public function testLeavesNothingHalfWrittenBehind(): void
    {
        $storage = $this->storage();

        $storage->patchProperties('calendars/work.ics', ['{DAV:}displayname' => 'Work']);

        self::assertSame([], glob($this->directory . '/*.writing'));
    }

    protected function storage(): IPropertyStorageBackend
    {
        return new PropertyStorage($this->directory);
    }
}
