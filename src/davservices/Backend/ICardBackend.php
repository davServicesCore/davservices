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

namespace DavServices\Backend;

use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * Where the address books and cards of RFC 6352 come from (R-BE-01).
 *
 * A directory, a database, whatever the application already has. The protocol
 * does not care: `CardDav\AddressBook` makes a collection an address book,
 * this says where its contents live, and the two never meet — a backend
 * interface may not reach up into the layer that uses it.
 *
 * ## It is shaped by one requirement
 *
 * > R-BE-02: Die Schnitte MÜSSEN N+1-Abfragen vermeidbar machen: Batch-Methoden
 * > für Multiget und für gemeinsame Metadaten bei `PROPFIND Depth: 1`.
 *
 * So there are two pairs rather than two methods. {@see cards()} answers the
 * metadata of a whole address book in one call and {@see card()} answers one;
 * {@see bodies()} answers many cards in one call and {@see body()} answers
 * one. The batched half of each pair is the one the protocol reaches for:
 * `PROPFIND Depth: 1` wants every entity tag at once, and
 * `CARDDAV:addressbook-multiget` (§8.7) wants forty cards at once.
 *
 * **The metadata carries no body** ({@see CardInfo}), and that absence is what
 * makes the split worth having: a client listing two hundred cards asks for
 * their sizes and tags, and a store that read two hundred files to answer
 * would have made the cheap question as dear as the expensive one.
 *
 * ## What an implementation owes (R-BE-03)
 *
 * Every method below says its preconditions, what it answers, and what it
 * throws. Three rules run through all of them:
 *
 * - **A missing *thing* is `null` or an empty list; a missing *container* is
 *   `NotFound`.** An address book with no cards in it is a perfectly good
 *   address book, and a store that threw would make "nothing here yet"
 *   indistinguishable from "something is broken". An address book nobody made
 *   is a `404`, because the server must not report a collection that is not
 *   there.
 * - **A member name is one path segment.** A store refuses anything that could
 *   reach outside the address book it is writing to, with `Forbidden` — the
 *   answer {@see File\Directory} already gives to a name it will not store.
 * - **A path that merely begins the same way is a different path.** `work2`
 *   does not lie inside `work`; a store that thought otherwise would hand out
 *   somebody else's cards.
 * - **No order is promised.** A store answers {@see addressBooks()} and
 *   {@see cards()} in whatever order its index has; `PROPFIND` does not ask
 *   for one and sorting a thousand members to satisfy nobody would be work
 *   thrown away. A caller that wants an order applies one.
 * - **An address book is named inside a principal's home, not globally.**
 *   Every method below is told whose it is, because two people both calling
 *   an address book `work` is the ordinary case rather than the exotic one —
 *   and a store keyed on the name alone would serve one of them the other's
 *   cards.
 *
 * `tests/unit/Backend/CardBackendContract.php` is the executable form of all
 * of that (R-BE-04), and every implementation that ships here runs it.
 */
interface ICardBackend
{
    /**
     * The address books this principal owns.
     *
     * What `CARDDAV:addressbook-home-set` (§7.1.1) points at the collection
     * of, and what a `PROPFIND Depth: 1` on that collection lists.
     *
     * **A principal the store knows nothing about has none**, which is not an
     * error: an account whose address books have not been provisioned yet is
     * an ordinary state, and `§6.3.1` leaves creating them optional.
     *
     * @param string $principal The principal's name, as
     *                          `Acl\PrincipalCollection` holds it
     *
     * @return list<AddressBookInfo> Empty where the principal owns none
     */
    public function addressBooks(string $principal): array;

    /**
     * The metadata of every card in an address book, in one call.
     *
     * **This is the `PROPFIND Depth: 1` half of R-BE-02.** It answers without
     * the bodies, because that is what the listing needs and all it needs.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     *
     * @throws NotFound If there is no address book of that name
     *
     * @return list<CardInfo> Empty where the address book holds no cards
     */
    public function cards(string $principal, string $addressBook): array;

    /**
     * The metadata of one card, or null where there is none of that name.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     * @param string $name The member name inside it
     *
     * @throws NotFound If there is no address book of that name
     */
    public function card(string $principal, string $addressBook, string $name): ?CardInfo;

    /**
     * The cards themselves, by name, in one call.
     *
     * **This is the multiget half of R-BE-02** — what
     * `CARDDAV:addressbook-multiget` (§8.7) asks for.
     *
     * **A name that is not there is left out rather than refused.** §8.7
     * reports per `DAV:href`, so a client asking for forty cards and one that
     * was deleted a moment ago is owed the thirty-nine; a store that threw
     * would turn one stale href into a failed report.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     * @param list<string> $names The member names wanted; an empty list
     *                            answers nothing rather than everything,
     *                            because asking for no cards is not asking
     *                            for all of them
     *
     * @throws NotFound If there is no address book of that name
     *
     * @return array<string, string> Keyed by member name, the card as it was
     *                               stored; names that are not there are
     *                               absent
     */
    public function bodies(string $principal, string $addressBook, array $names): array;

    /**
     * One card as it was stored, or null where there is none of that name.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     * @param string $name The member name inside it
     *
     * @throws NotFound If there is no address book of that name
     */
    public function body(string $principal, string $addressBook, string $name): ?string;

    /**
     * Puts a card in, replacing one of that name if it is there.
     *
     * **It stores what it is given and judges nothing.** Whether the bytes are
     * a vCard, whether the `UID` clashes, whether the card is longer than
     * `CARDDAV:max-resource-size` — all of that is §6.3.2.1's preconditions,
     * which belong to the layer that speaks HTTP and can answer with the right
     * one. A store that validated would be answering a question it cannot
     * phrase the answer to.
     *
     * **It answers with the entity tag the card now has, or with null**, and
     * §6.3.2.3 is why there are two answers rather than one:
     *
     * > Servers SHOULD return a strong entity tag (ETag header) in a PUT
     * > response **when the stored address object resource is equivalent by
     * > octet equality** to the address object resource submitted in the body
     * > of the PUT request. […] In the case where the data stored by a server
     * > as a result of a PUT request is not equivalent by octet equality to
     * > the submitted address object resource […] a strong entity tag MUST NOT
     * > be returned in the response.
     *
     * So a store that keeps the bytes it was given answers a tag, and a store
     * that normalises the card on the way in answers **null** — which is how
     * it says the client must fetch the card again rather than trust what it
     * sent. An interface with one answer would have forced the second kind of
     * store to lie.
     *
     * The tag is **strong**: §6.3.2.3 opens with "The DAV:getetag property
     * MUST be defined and set to a strong entity tag on all address object
     * resources", so a `W/`-prefixed tag is not an answer here. It changes
     * whenever the card does — §9.2 has clients avoid lost updates with it —
     * and the tag a later {@see card()} reports is the same one.
     *
     * Both stores that ship here keep the bytes verbatim, so both always
     * answer a tag.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     * @param string $name The member name, which must be a single path
     *                     segment
     * @param string $card The card, as it is to be stored
     *
     * @throws NotFound If there is no address book of that name
     * @throws Forbidden If the name is not one this store will take
     *
     * @return string|null The strong entity tag, quoted, or null where the
     *                     store did not keep the bytes it was given
     */
    public function put(string $principal, string $addressBook, string $name, string $card): ?string;

    /**
     * Takes a card out.
     *
     * **A card that is not there is `NotFound` rather than nothing done.** A
     * `DELETE` on a resource that has gone is a `404` (RFC 4918 §9.6), and a
     * store that shrugged would have the server answer `204` for a card it
     * never had.
     *
     * @param string $principal The principal who owns it
     * @param string $addressBook Its name inside that principal's home
     * @param string $name The member name inside it
     *
     * @throws NotFound If there is no address book of that name, or no card
     *                  of that name in it
     */
    public function remove(string $principal, string $addressBook, string $name): void;
}
