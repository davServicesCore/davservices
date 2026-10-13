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

use DavServices\Backend\File\CardBackend;
use DavServices\Backend\ICardBackend;
use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use DavServices\Exception\NotFound;
use DavServices\Tests\Unit\Backend\CardBackendContract;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The contract, run against the store that keeps its cards in files, plus what
 * only a file store can get wrong.
 */
#[CoversClass(CardBackend::class)]
final class CardBackendTest extends CardBackendContract
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-cards-' . bin2hex(random_bytes(8));

        foreach (['', '/simon', '/simon/work', '/simon/home', '/tim', '/tim/work'] as $part) {
            if (!mkdir($directory . $part) && !is_dir($directory . $part)) {
                self::fail(sprintf('The test could not make "%s".', $directory . $part));
            }
        }

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        self::removeEverythingUnder($this->directory);
    }

    /**
     * A directory this library made up is a directory nobody meant to have.
     * The application says where its data goes, and says it once.
     */
    public function testRefusesADirectoryThatIsNotThere(): void
    {
        $this->expectException(RuntimeException::class);

        new CardBackend($this->directory . '/nowhere');
    }

    /**
     * **A card is a file a person can read.** That is the whole reason this
     * backend exists, so it is asserted rather than assumed.
     */
    public function testACardIsAFileAPersonCanRead(): void
    {
        $this->backend()->put('simon', 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));

        self::assertStringEqualsFile(
            $this->directory . '/simon/work/tim.vcf',
            self::card('tim', 'Tim Howes'),
        );
    }

    /**
     * **A directory that is not an address book is not listed.** The listing
     * takes the directories under a principal's own, so a file dropped in
     * beside them must not look like an address book.
     */
    public function testAFileBesideTheAddressBooksIsNotOne(): void
    {
        file_put_contents($this->directory . '/simon/notes.txt', 'nothing to do with cards');

        $names = array_map(
            static fn (AddressBookInfo $book): string => $book->name(),
            $this->backend()->addressBooks('simon'),
        );

        sort($names);

        self::assertSame(['home', 'work'], $names);
    }

    /**
     * **Every file in an address book is taken to be a card**, and the member
     * name is the file name exactly — suffix and all.
     *
     * A client chooses the member name and RFC 6352 asks nothing of it, so a
     * store that only listed `*.vcf` would hide a card somebody called
     * `simon`. §5.2 is what licenses the other direction: an address book
     * "MUST only contain address object resources and collections that are not
     * address book collections", so a file in here is meant to be a card.
     */
    public function testEveryFileInAnAddressBookIsTakenToBeACard(): void
    {
        $this->backend()->put('simon', 'work', 'no-suffix', self::card('plain', 'No Suffix'));

        $names = array_map(
            static fn (CardInfo $card): string => $card->name(),
            $this->backend()->cards('simon', 'work'),
        );

        sort($names);

        self::assertSame(['no-suffix', 'simon.vcf'], $names);
        self::assertFileExists($this->directory . '/simon/work/no-suffix');
    }

    /**
     * **The `UID` is answered as unknown, and that is the honest answer.**
     * The only way this store could know it is by reading the card, which is
     * what the listing exists to avoid — so it says so rather than guessing,
     * and a server on this backend pays for `CARDDAV:no-uid-conflict` by
     * reading.
     */
    public function testTheUidIsAnsweredAsUnknown(): void
    {
        self::assertNull($this->backend()->card('simon', 'work', 'simon.vcf')?->uid());
    }

    /**
     * **A card is written beside its place and moved in**, so that a write
     * cut in half leaves the old card rather than half of the new one — and
     * so that no part file is left lying about afterwards.
     */
    public function testAWriteLeavesNoPartFileBehind(): void
    {
        $this->backend()->put('simon', 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));

        $leftovers = glob($this->directory . '/simon/work/*.part');

        self::assertSame([], $leftovers === false ? [] : $leftovers);
    }

    /**
     * **A name that could reach outside is no card of this address book.** On
     * the way out there is nothing to protect but the answer, so a reader
     * asking for a traversal is told there is no such card rather than being
     * refused — and nothing outside the directory is opened.
     */
    public function testAskingForSomethingOutsideTheAddressBookFindsNoCard(): void
    {
        $backend = $this->backend();

        self::assertNull($backend->card('simon', 'work', '../../escaped'));
        self::assertNull($backend->body('simon', 'work', '../../escaped'));
    }

    /**
     * **A write that cannot be made is said out loud.**
     *
     * Reachable without breaking a filesystem: a directory standing where the
     * card would go. The part file lands beside it under its own name, and the
     * move in then fails — which is the one failure of this path a test can
     * produce honestly, and the one a client would hit by `PUT`ting a card
     * over the name of a collection.
     */
    public function testAWriteThatCannotBeMadeIsSaidOutLoud(): void
    {
        $inTheWay = $this->directory . '/simon/work/taken';

        if (!mkdir($inTheWay) || !mkdir($inTheWay . '/so-it-is-not-empty')) {
            self::fail('The test could not put a directory in the way.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be written/');

        $this->backend()->put('simon', 'work', 'taken', self::card('x', 'X'));
    }

    /**
     * And it leaves nothing behind when it fails, which is the other half of
     * writing beside and moving in: a failed `PUT` must not litter the address
     * book with part files for a listing to report as cards.
     */
    public function testAFailedWriteLeavesNoPartFileBehind(): void
    {
        $inTheWay = $this->directory . '/simon/work/taken';

        if (!mkdir($inTheWay) || !mkdir($inTheWay . '/so-it-is-not-empty')) {
            self::fail('The test could not put a directory in the way.');
        }

        try {
            $this->backend()->put('simon', 'work', 'taken', self::card('x', 'X'));
        } catch (RuntimeException) {
            // The refusal is the other test's business.
        }

        $leftovers = glob($this->directory . '/simon/work/*.part');

        self::assertSame([], $leftovers === false ? [] : $leftovers);
    }

    /**
     * **The entity tag a write answers with describes the card that was just
     * written**, not the one that was there before.
     *
     * PHP caches `stat()` per path within a request, so a `put()` over a card
     * something had already looked at would otherwise report the old length —
     * and a client would be handed an entity tag for data it never received.
     * The store clears the cache for that one path; this is the test that
     * notices if it stops.
     */
    public function testTheEntityTagOfAWriteDescribesWhatWasJustWritten(): void
    {
        $backend = $this->backend();
        $longer = self::card('simon', 'Simon Perreault of Viagenie, Quebec');

        // Reads the card's stat into PHP's cache, as a `PROPFIND` before a
        // `PUT` in the same request would.
        $backend->card('simon', 'work', 'simon.vcf');

        $answered = $backend->put('simon', 'work', 'simon.vcf', $longer);

        clearstatcache();

        $fresh = $backend->card('simon', 'work', 'simon.vcf');

        self::assertNotNull($fresh);
        self::assertSame(strlen($longer), $fresh->size());
        self::assertSame($fresh->etag(), $answered);
    }

    /**
     * **A principal that is no name cannot be used to walk out of the store.**
     *
     * This is the test the guard exists for, and a mutation run showed why the
     * obvious one is not enough: asking for a principal called `../escaped`
     * fails anyway, because nothing of that name is there. The guard only
     * matters when the traversal would land somewhere real — so this puts a
     * directory next to the store and shows it stays out of reach.
     */
    public function testAPrincipalCannotBeUsedToWalkOutOfTheStore(): void
    {
        $outside = $this->directory . '-outside';

        if (!mkdir($outside) || !mkdir($outside . '/work')) {
            self::fail('The test could not put a directory beside the store.');
        }

        file_put_contents($outside . '/work/secret.vcf', self::card('x', 'Not Yours'));

        try {
            $this->expectException(NotFound::class);

            $this->backend()->cards('../' . basename($outside), 'work');
        } finally {
            self::removeEverythingUnder($outside);
        }
    }

    protected function backend(): ICardBackend
    {
        $backend = new CardBackend($this->directory);

        if (!is_file($this->directory . '/simon/work/simon.vcf')) {
            $backend->put('simon', 'work', 'simon.vcf', self::card('simon', 'Simon Perreault'));
        }

        return $backend;
    }

    protected function secondPrincipal(): string
    {
        return 'tim';
    }

    /**
     * Everything under a directory, and then the directory.
     */
    private static function removeEverythingUnder(string $directory): void
    {
        $entries = glob($directory . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            is_dir($entry) ? self::removeEverythingUnder($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
