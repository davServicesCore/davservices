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

use DavServices\Backend\File\PrincipalBackend;
use DavServices\Backend\IPrincipalBackend;
use DavServices\Tests\Unit\Backend\PrincipalBackendContract;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The contract, run against the people kept in a directory, plus what only a
 * file store can get wrong.
 *
 * **This backend is read and never written, and that turns the design of the
 * other file backends around.** `IPrincipalBackend` has no write methods, so
 * the files are made by a person rather than by the library — and a person
 * has to be able to find them again. The lock and property stores name their
 * files after a hash because nobody but the library ever looks; here somebody
 * does.
 *
 * **The name inside the file is the one that counts**, and the filename is
 * not read at all. That is what keeps a principal from being called
 * `../../etc` and reaching out of the directory: a name that arrives as
 * content cannot be a path.
 */
#[CoversClass(PrincipalBackend::class)]
final class PrincipalBackendTest extends PrincipalBackendContract
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-principals-' . bin2hex(random_bytes(8));

        if (!mkdir($directory) && !is_dir($directory)) {
            self::fail(sprintf('The test could not make "%s".', $directory));
        }

        $this->directory = $directory;

        $this->write('alice.xml', '
            <principal xmlns="https://dav.services/principals" name="alice">
                <display-name>Alice Ashton</display-name>
                <alternate-uri>mailto:alice@example.test</alternate-uri>
            </principal>
        ');

        $this->write('plain.xml', '<principal xmlns="https://dav.services/principals" name="plain"/>');
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

        new PrincipalBackend($this->directory . '/nowhere');
    }

    /**
     * **The filename is not the name.** A person edits these files, so they
     * may be called whatever suits — and the name that counts comes from
     * inside, which is also what keeps one from being a path.
     */
    public function testTakesTheNameFromInsideTheFileRatherThanFromItsName(): void
    {
        $this->write('somebody-elses-filing-system.xml', '
            <principal xmlns="https://dav.services/principals" name="bob">
                <display-name>Bob Brewer</display-name>
            </principal>
        ');

        self::assertSame('Bob Brewer', $this->backend()->principal('bob')?->displayName());
    }

    /**
     * And a name that would be a path is simply a name, because nothing is
     * ever built from it: the file it came from was found by reading the
     * directory, not by joining anything.
     */
    public function testANameThatLooksLikeAPathIsJustAName(): void
    {
        $this->write('odd.xml', '<principal xmlns="https://dav.services/principals" name="../../etc/passwd"/>');

        self::assertSame('../../etc/passwd', $this->backend()->principal('../../etc/passwd')?->name());
    }

    /**
     * **An address but no chosen name**, which is the usual shape of somebody
     * imported from a directory: there is a `mailto:` for them and nobody has
     * said what to call them. A backend that took the first child element for
     * the display name would hand the address out as a person's name.
     */
    public function testAPersonMayHaveAnAddressAndNoName(): void
    {
        $this->write('erin.xml', '
            <principal xmlns="https://dav.services/principals" name="erin">
                <alternate-uri>mailto:erin@example.test</alternate-uri>
            </principal>
        ');

        $erin = $this->backend()->principal('erin');

        self::assertNull($erin?->displayName());
        self::assertSame(['mailto:erin@example.test'], $erin?->alternateUris());
    }

    /**
     * Whatever else is in the directory belongs to somebody else, and
     * reading the people must not take it along.
     */
    public function testLeavesAloneWhatIsNotItsOwn(): void
    {
        $stranger = $this->directory . '/notes.txt';

        file_put_contents($stranger, 'Nothing to do with principals.');

        self::assertCount(2, $this->backend()->principals());
        self::assertFileExists($stranger);
    }

    /**
     * **A file that cannot be read is not a person who does not exist.**
     * Where the property storage skips a line somebody edited wrongly, this
     * refuses the request: a property that goes missing is an inconvenience,
     * and a principal that goes missing is somebody who cannot sign in — or
     * whose access control entries quietly stop matching anybody.
     */
    public function testRefusesToReadAStoreThatHasBeenDamaged(): void
    {
        $this->write('damaged.xml', '<principal');

        $this->expectException(RuntimeException::class);

        $this->backend()->principals();
    }

    /**
     * XML a parser is happy with, holding something that is not a principal:
     * a file somebody else put there, or one from a version that wrote them
     * differently.
     */
    public function testRefusesAFileThatIsNoPrincipalOfThisServer(): void
    {
        $this->write('stranger.xml', '<greeting xmlns="urn:example">Hello</greeting>');

        $this->expectException(RuntimeException::class);

        $this->backend()->principals();
    }

    /**
     * A principal with no name is nobody. Reading it as one would put an
     * entry in the collection that no path can reach.
     */
    public function testRefusesAPrincipalWithNoName(): void
    {
        $this->write('nameless.xml', '<principal xmlns="https://dav.services/principals" name=""/>');

        $this->expectException(RuntimeException::class);

        $this->backend()->principals();
    }

    /**
     * **The directory is read once per instance.** Every request asks about
     * at least one principal and a listing asks about all of them; reading it
     * per question would be reading it several times for one answer.
     */
    public function testReadsTheDirectoryOnce(): void
    {
        $backend = $this->backend();

        $backend->principal('alice');

        unlink($this->directory . '/alice.xml');

        self::assertNotNull($backend->principal('alice'), 'The second question is answered from what was read.');
    }

    /**
     * And a new instance sees what has changed, because a principal added
     * while the server runs should be there for the **next** request rather
     * than the one after.
     */
    public function testTheNextRequestSeesWhatHasChanged(): void
    {
        $this->write('carol.xml', '<principal xmlns="https://dav.services/principals" name="carol"/>');

        self::assertNotNull($this->backend()->principal('carol'));
    }

    protected function backend(): IPrincipalBackend
    {
        return new PrincipalBackend($this->directory);
    }

    private function write(string $name, string $document): void
    {
        file_put_contents($this->directory . '/' . $name, trim($document));
    }
}
