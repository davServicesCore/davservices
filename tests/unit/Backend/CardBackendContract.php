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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\ICardBackend;
use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every card store has to do, whatever it keeps its cards in. R-BE-04.
 *
 * Written once and run against each implementation, because a backend that is
 * exchangeable is only exchangeable if they all behave the same. An
 * application that swapped one for another and found its address books gone
 * would have been better off with no choice at all.
 *
 * ## The two promises R-BE-02 is about
 *
 * > R-BE-02: Die Schnitte MÜSSEN N+1-Abfragen vermeidbar machen: Batch-Methoden
 * > für Multiget und für gemeinsame Metadaten bei `PROPFIND Depth: 1`.
 *
 * - **`cards()` answers for the whole address book in one call**, with no
 *   bodies in the answer. A `PROPFIND Depth: 1` over two hundred cards asks
 *   for entity tags and sizes; a store that made that cost two hundred reads
 *   would make the cheap question as dear as the expensive one.
 * - **`bodies()` answers for many names in one call**, which is what
 *   `CARDDAV:addressbook-multiget` (§8.7) is. A name that is not there is left
 *   out rather than refused, because one card deleted mid-request must not
 *   sink a report about forty others.
 *
 * ## And the ones that are easiest to get wrong
 *
 * **An entity tag changes when the card does, and not otherwise.** RFC 6352
 * §9.2 has clients avoid lost updates with it, so a tag that stayed the same
 * across a write would let a client overwrite something it had never seen.
 *
 * **A `UID` is answered without reading the card**, or answered as null. Both
 * are allowed; what is not allowed is guessing. R-CARD-07 needs the UIDs of a
 * whole address book to answer `CARDDAV:no-uid-conflict`, and a store that had
 * to read every body for that would make one `PUT` cost the collection.
 *
 * **A path that merely begins the same way is a different one.** `work2` is
 * not inside `work`, and a store that thinks otherwise hands out somebody
 * else's cards.
 */
abstract class CardBackendContract extends TestCase
{
    /**
     * A store with one address book, `work`, holding one card.
     */
    abstract protected function backend(): ICardBackend;

    /**
     * The principal whose address books the store was set up with.
     */
    protected function principal(): string
    {
        return 'simon';
    }

    public function testListsTheAddressBooksOfAPrincipal(): void
    {
        $names = array_map(
            static fn (AddressBookInfo $book): string => $book->name(),
            $this->backend()->addressBooks($this->principal()),
        );

        // No order is promised — a store may answer in whatever order its
        // index has — so the test asks what is there rather than where.
        self::assertContains('work', $names);
    }

    /**
     * A principal the store knows nothing about has no address books. That is
     * not an error: a `PROPFIND` on an empty home is a perfectly good request,
     * and a store that threw would make "no address books yet" indistinguishable
     * from "something is broken".
     */
    public function testAPrincipalWithNoAddressBooksHasNone(): void
    {
        self::assertSame([], $this->backend()->addressBooks('nobody-at-all'));
    }

    public function testFindsOneCardByName(): void
    {
        $card = $this->backend()->card($this->principal(), 'work', 'simon.vcf');

        self::assertNotNull($card);
        self::assertSame('simon.vcf', $card->name());
        self::assertGreaterThan(0, $card->size());
        self::assertNotSame('', $card->etag());
    }

    public function testKnowsNothingAboutACardThatIsNotThere(): void
    {
        self::assertNull($this->backend()->card($this->principal(), 'work', 'nobody.vcf'));
        self::assertNull($this->backend()->body($this->principal(), 'work', 'nobody.vcf'));
    }

    /**
     * **The metadata of a whole address book in one call**, which is the
     * `PROPFIND Depth: 1` half of R-BE-02.
     */
    public function testAnswersTheMetadataOfAWholeAddressBookAtOnce(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));

        $names = array_map(
            static fn (CardInfo $card): string => $card->name(),
            $backend->cards($this->principal(), 'work'),
        );

        sort($names);

        self::assertSame(['simon.vcf', 'tim.vcf'], $names);
    }

    public function testAnAddressBookWithNoCardsIsEmptyRatherThanAnError(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'work', 'simon.vcf');

        self::assertSame([], $backend->cards($this->principal(), 'work'));
    }

    /**
     * An address book nobody made is not an empty one. A `PROPFIND` on it has
     * to be a `404`, and a store answering an empty list would have the server
     * report a collection that is not there.
     */
    public function testAnAddressBookThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->cards($this->principal(), 'nothing-like-it');
    }

    /**
     * **Many bodies in one call**, which is what `addressbook-multiget` is.
     */
    public function testAnswersManyBodiesAtOnce(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));

        $bodies = $backend->bodies($this->principal(), 'work', ['simon.vcf', 'tim.vcf']);

        self::assertCount(2, $bodies);
        self::assertStringContainsString('Simon Perreault', $bodies['simon.vcf'] ?? '');
        self::assertStringContainsString('Tim Howes', $bodies['tim.vcf'] ?? '');
    }

    /**
     * **A name that is not there is left out, not refused.** RFC 6352 §8.7
     * reports per href, so a client asking for forty cards and one that has
     * just been deleted is owed the thirty-nine.
     */
    public function testANameThatIsNotThereIsLeftOutOfTheBodies(): void
    {
        $bodies = $this->backend()->bodies($this->principal(), 'work', ['simon.vcf', 'gone.vcf']);

        self::assertArrayHasKey('simon.vcf', $bodies);
        self::assertArrayNotHasKey('gone.vcf', $bodies);
    }

    public function testAskingForNoBodiesAnswersNothing(): void
    {
        self::assertSame([], $this->backend()->bodies($this->principal(), 'work', []));
    }

    /**
     * **An address book that is not there is refused even when nothing is
     * asked of it.** The interface says `bodies()` throws for an unknown
     * address book, full stop — and a store that checked only while walking
     * the names would answer an empty list for a collection that does not
     * exist, which is a `404` reported as a `207`.
     *
     * A mutation run found this: removing the guard from `bodies()` killed no
     * test, because every test of it named at least one card.
     */
    public function testAnAddressBookThatIsNotThereIsRefusedEvenForNoNames(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->bodies($this->principal(), 'nothing-like-it', []);
    }

    /**
     * **A principal that is no name is no principal.** The address book may
     * be real and the principal still nonsense — a store that checked only
     * one of the two would walk out of its own root.
     */
    public function testAPrincipalThatIsNoNameIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->cards('../escaped', 'work');
    }

    public function testKeepsACardAndHandsItBack(): void
    {
        $backend = $this->backend();
        $card = self::card('tim', 'Tim Howes');

        $etag = $backend->put($this->principal(), 'work', 'tim.vcf', $card);

        // Octet equality, which is what §6.3.2.3 makes the strong entity tag
        // conditional on — so a store answering a tag at all is claiming this.
        self::assertSame($card, $backend->body($this->principal(), 'work', 'tim.vcf'));

        self::assertNotNull($etag);
        self::assertNotSame('', $etag);

        // §6.3.2.3: "The DAV:getetag property MUST be defined and set to a
        // strong entity tag on all address object resources." A `W/` prefix
        // is a weak one.
        self::assertStringStartsNotWith('W/', $etag);
    }

    /**
     * **An entity tag changes when the card does.** §9.2 has clients avoid
     * lost updates with it; a tag that survived a write would let one client
     * overwrite what another had just put there.
     */
    public function testTheEntityTagChangesWhenTheCardDoes(): void
    {
        $backend = $this->backend();
        $before = $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));
        $after = $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Timothy Howes'));

        self::assertNotSame($before, $after);
        self::assertSame($after, $backend->card($this->principal(), 'work', 'tim.vcf')?->etag());
    }

    /**
     * And it is the same tag whoever asks, because a client comparing the one
     * from a `PROPFIND` with the one from a `GET` has to find them equal.
     */
    public function testTheEntityTagIsTheSameWhoeverAsks(): void
    {
        $backend = $this->backend();
        $etag = $backend->card($this->principal(), 'work', 'simon.vcf')?->etag();

        $fromTheListing = null;

        foreach ($backend->cards($this->principal(), 'work') as $card) {
            if ($card->name() === 'simon.vcf') {
                $fromTheListing = $card->etag();
            }
        }

        self::assertNotNull($etag);
        self::assertSame($etag, $fromTheListing);
    }

    /**
     * **The `UID` is answered without reading the card, or answered as null.**
     * R-CARD-07 needs the UIDs of a whole address book to tell whether a `PUT`
     * would clash, and a store that read every body for that would make one
     * write cost the collection.
     */
    public function testTheUidIsEitherKnownOrHonestlyUnknown(): void
    {
        $card = $this->backend()->card($this->principal(), 'work', 'simon.vcf');

        self::assertNotNull($card);

        $uid = $card->uid();

        if ($uid !== null) {
            self::assertSame('simon@example.com', $uid);
        }
    }

    public function testRemovesACard(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'work', 'simon.vcf');

        self::assertNull($backend->card($this->principal(), 'work', 'simon.vcf'));
    }

    public function testRemovingACardThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->remove($this->principal(), 'work', 'nobody.vcf');
    }

    /**
     * A member name is a path segment, and a store that let one carry a
     * separator would let a client reach outside the address book it was
     * writing to.
     *
     * `Forbidden` rather than `Conflict`, which is the answer
     * {@see \DavServices\Backend\File\Directory} already gives to a name it
     * will not store: `409` is about a parent that is not there, `403` about a
     * name the server refuses.
     */
    public function testAMemberNameMayNotReachOutOfItsAddressBook(): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->put($this->principal(), 'work', '../escaped.vcf', self::card('x', 'X'));
    }

    /**
     * **A name that merely begins the same way is a different name.** `work2`
     * is not inside `work`; a store that thought otherwise would hand out
     * somebody else's cards.
     */
    public function testAnAddressBookThatBeginsTheSameWayIsADifferentOne(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->cards($this->principal(), 'work2');
    }

    /**
     * **An address book says what it is called and what it is for.** §6.3.1
     * asks clients to prefer `DAV:displayname` over the URL segment, which
     * "may be opaque and not represent any meaningful human-readable text",
     * and §6.2.1's description is the cue a user is given about what an extra
     * address book is for.
     *
     * Both are null where nothing is set, which is **not** the same as empty:
     * a `PROPFIND` for an unset property is a `404`, and an empty string would
     * claim the user named their address book nothing at all.
     */
    public function testAnAddressBookSaysWhatItIsCalledAndWhatItIsFor(): void
    {
        $named = null;
        $unnamed = null;

        foreach ($this->backend()->addressBooks($this->principal()) as $book) {
            $named = $book->name() === 'work' ? $book : $named;
            $unnamed = $book->name() === 'home' ? $book : $unnamed;
        }

        self::assertNotNull($named);

        if ($named->displayName() !== null) {
            self::assertSame('Work', $named->displayName());
        }

        if ($unnamed !== null) {
            self::assertNull($unnamed->description());
        }
    }

    /**
     * **A card says when it last changed, or says it does not keep that.**
     * `DAV:getlastmodified` is answered from it, and a store inventing a time
     * would have clients believe a card is newer than it is.
     */
    public function testACardSaysWhenItLastChangedOrSaysItDoesNotKnow(): void
    {
        $card = $this->backend()->card($this->principal(), 'work', 'simon.vcf');

        self::assertNotNull($card);

        $changed = $card->lastModified();

        if ($changed !== null) {
            self::assertGreaterThan(0, $changed->getTimestamp());
        }
    }

    /**
     * **An empty name is no name**, and neither are the two that mean a
     * directory. A store that took one would write beside its address book
     * rather than inside it.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('namesThatAreNotNames')]
    public function testANameThatIsNoNameIsRefused(string $name): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->put($this->principal(), 'work', $name, self::card('x', 'X'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotNames(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'this directory' => ['.'];

        yield 'the one above' => ['..'];
    }

    /**
     * **Two principals may both call an address book `work`.**
     *
     * This is the ordinary case rather than the exotic one, and a store keyed
     * on the name alone would serve one of them the other's cards — which is
     * why every method here is told whose address book it is. The first draft
     * of this interface was not, and nothing but this test would have said so.
     */
    public function testTwoPrincipalsMayBothCallAnAddressBookTheSame(): void
    {
        $backend = $this->backend();
        $other = $this->secondPrincipal();

        $backend->put($other, 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));

        $mine = array_map(static fn (CardInfo $card): string => $card->name(), $backend->cards($this->principal(), 'work'));
        $theirs = array_map(static fn (CardInfo $card): string => $card->name(), $backend->cards($other, 'work'));

        self::assertSame(['simon.vcf'], $mine);
        self::assertSame(['tim.vcf'], $theirs);
    }

    /**
     * A second principal with an address book of the same name.
     *
     * **Abstract rather than optional.** Serving two people who both called
     * an address book `work` is a promise of the interface, not a feature a
     * store may decline — so a store that cannot be set up to show it cannot
     * claim conformance either.
     */
    abstract protected function secondPrincipal(): string;

    /**
     * One card, with the `UID` the contract above expects.
     */
    protected static function card(string $uid, string $fullName): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:4.0',
            'UID:' . $uid . '@example.com',
            'FN:' . $fullName,
            'END:VCARD',
        ]) . "\r\n";
    }
}
