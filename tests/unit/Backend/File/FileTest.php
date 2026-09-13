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

use DavServices\Backend\File\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test list, derived from R-TREE-02 (a file is handed over as a stream and
 * delivered streaming), R-TREE-03 (a write may arrive as a stream) and
 * R-BE-05 (this is a reference backend).
 *
 * A file on a disc is the simplest backend there is, and the one everything
 * else is measured against. Two things about it are worth more than they look.
 *
 * **Nothing is read into a string.** A file goes out as the handle it is, and
 * a write goes in the same way. A backend that read a recording into memory to
 * hand it over would fall over on the first large one rather than the tenth,
 * and every backend written after this one will copy what this one does.
 *
 * **The entity tag is made without reading the file.** Size and time of change
 * are what a filesystem can answer at no cost; hashing the content would mean
 * reading every byte of every file in a listing to answer a `PROPFIND`.
 */
#[CoversClass(File::class)]
final class FileTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-file-' . bin2hex(random_bytes(8));

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

    public function testKnowsWhatItIsCalled(): void
    {
        self::assertSame('work.ics', $this->file('work.ics', 'a meeting')->name());
    }

    /**
     * And where it lies, which is what a collection of the same backend needs
     * to copy or move it in one operation rather than through the server.
     */
    public function testKnowsWhereItLies(): void
    {
        self::assertSame($this->directory . '/work.ics', $this->file('work.ics', 'a meeting')->location());
    }

    /**
     * R-TREE-02: the handle is what goes out, and the server sends from it. A
     * backend that read the file into a string would decide, on behalf of
     * every server built on it, how large a file may be.
     */
    public function testHandsOverAStreamRatherThanItsContent(): void
    {
        $content = $this->file('work.ics', 'a meeting')->get();

        self::assertSame('resource (stream)', get_debug_type($content), 'The file came back as something other than a stream.');
        self::assertSame('a meeting', stream_get_contents($content));
    }

    /**
     * R-TREE-03: and a write arrives the same way.
     */
    public function testTakesAWriteThatArrivesAsAStream(): void
    {
        $file = $this->file('work.ics', 'the old one');
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);
        fwrite($stream, 'the new one');
        rewind($stream);

        $file->put($stream);

        self::assertSame('the new one', file_get_contents($this->directory . '/work.ics'));
    }

    public function testTakesAWriteThatArrivesAsAString(): void
    {
        $file = $this->file('work.ics', 'the old one');

        $file->put('the new one');

        self::assertSame('the new one', file_get_contents($this->directory . '/work.ics'));
    }

    /**
     * A write says what the file is tagged as now, so that the `PUT` that made
     * it can answer with it and the client need not ask again.
     */
    public function testSaysWhatItIsTaggedAsAfterAWrite(): void
    {
        $file = $this->file('work.ics', 'the old one');

        self::assertSame($file->etag(), $file->put('the new one'));
    }

    /**
     * PHP keeps what it was told about a path, and a write through this very
     * object does not change its mind. A file written a moment ago would
     * otherwise be reported with the length it had before — and the client
     * would store that as the truth.
     */
    public function testAsksTheFilesystemAgainAfterAWrite(): void
    {
        $file = $this->file('work.ics', 'short');

        self::assertSame(5, $file->contentLength());

        $file->put('a much longer meeting than that');

        self::assertSame(31, $file->contentLength());
    }

    public function testKnowsHowLongItIs(): void
    {
        self::assertSame(9, $this->file('work.ics', 'a meeting')->contentLength());
    }

    public function testKnowsWhenItLastChanged(): void
    {
        $changed = $this->file('work.ics', 'a meeting')->lastModified();

        self::assertNotNull($changed);
        self::assertEqualsWithDelta(time(), $changed->getTimestamp(), 5);
    }

    /**
     * The tag changes when the content does, which is the whole of what a
     * client needs it for.
     */
    public function testIsTaggedDifferentlyOnceItHasChanged(): void
    {
        $file = $this->file('work.ics', 'a meeting');
        $before = $file->etag();

        $file->put('a longer meeting than before');

        self::assertNotSame($before, $file->etag());
    }

    /**
     * RFC 9110 §8.8.3: an entity tag is quoted, and this one is weak. Size and
     * time of change cannot tell two changes within the same second apart, and
     * saying so is better than letting a client believe a strong comparison.
     */
    public function testIsTaggedWeaklyAndInQuotes(): void
    {
        self::assertMatchesRegularExpression('/^W\/"[0-9a-f-]+"$/', (string) $this->file('work.ics', 'a meeting')->etag());
    }

    /**
     * The type comes from the name, because a file on a disc carries no type
     * of its own. There is no `finfo` here on purpose: it is an extension, and
     * a library that needs one has decided something for its users.
     */
    #[DataProvider('types')]
    public function testSaysWhatTypeItIsByItsName(string $name, string $type): void
    {
        self::assertSame($type, $this->file($name, 'x')->contentType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function types(): iterable
    {
        yield 'a calendar object' => ['work.ics', 'text/calendar'];
        yield 'a contact' => ['alice.vcf', 'text/vcard'];
        yield 'plain text' => ['notes.txt', 'text/plain'];
        yield 'a document' => ['handbook.pdf', 'application/pdf'];
        yield 'a picture' => ['logo.png', 'image/png'];
        yield 'the same in capitals' => ['LOGO.PNG', 'image/png'];
        yield 'something nobody knows' => ['backup.qqq', 'application/octet-stream'];
        yield 'no extension at all' => ['LICENCE', 'application/octet-stream'];
    }

    public function testGoesAwayWhenItIsDeleted(): void
    {
        $file = $this->file('work.ics', 'a meeting');

        $file->delete();

        self::assertFileDoesNotExist($this->directory . '/work.ics');
    }

    /**
     * A file that has gone away under the request is not answered with an
     * empty one: the client would store that as the truth.
     */
    public function testRefusesToReadWhatIsNoLongerThere(): void
    {
        $file = $this->file('work.ics', 'a meeting');

        unlink($this->directory . '/work.ics');

        $this->expectException(RuntimeException::class);

        $file->get();
    }

    public function testSaysNothingAboutAFileThatIsNoLongerThere(): void
    {
        $file = $this->file('work.ics', 'a meeting');

        unlink($this->directory . '/work.ics');

        self::assertNull($file->contentLength());
        self::assertNull($file->lastModified());
        self::assertNull($file->etag());
    }

    private function file(string $name, string $content): File
    {
        file_put_contents($this->directory . '/' . $name, $content);

        return new File($this->directory . '/' . $name, $name);
    }
}
