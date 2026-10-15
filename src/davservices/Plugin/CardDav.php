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

namespace DavServices\Plugin;

use Closure;
use DavServices\Acl\Principal;
use DavServices\CardDav\AddressBook;
use DavServices\CardDav\AddressData;
use DavServices\CardDav\AddressObject;
use DavServices\CardDav\Report\AddressBookMultiGet;
use DavServices\CardDav\Report\AddressBookQuery;
use DavServices\Dav\Collation\Collations;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Report;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Exception\PayloadTooLarge;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * What a client is told about address books, and the one rule §5.2 cannot
 * leave to a node (RFC 6352).
 *
 * Switched on in one line, like everything else here:
 *
 *     (new CardDav($server, 'addressbooks'))->register($report);
 *
 * That line is the difference between a WebDAV server with some collections in
 * it and a CardDAV server. §6.1 makes the `DAV` header the promise: "A server
 * supporting the features described in this document MUST include
 * 'addressbook' as a field in the DAV response header" — and a client that
 * does not read it there will never look for an address book at all.
 *
 * ## Four properties, and why a plugin answers them
 *
 * Two are **limits the server sets** rather than data a store holds.
 * `CARDDAV:supported-address-data` (§6.2.2) says which versions of vCard this
 * server will keep, and `CARDDAV:max-resource-size` (§6.2.3) how large a card
 * may be. Both are "MUST be protected as it indicates the level of support
 * provided by the server" in the memo's own words, so they belong to the
 * server rather than to the collection.
 *
 * Two are about **where** something is. `CARDDAV:addressbook-home-set`
 * (§7.1.1) and `CARDDAV:principal-address` (§7.1.2) hang on a principal, and
 * a node knows its name and nothing about where it hangs — the same reason
 * {@see Principals} exists. The same principal mounted at `/dav/` has a
 * different home set, and a node that guessed would send clients where nothing
 * answers.
 *
 * ## Two properties this deliberately does not answer
 *
 * **`CARDDAV:addressbook-description`.** §6.2.1: "SHOULD NOT be protected so
 * that users can specify a description." A plugin answering it would make it
 * unwritable, which is the opposite of what the memo asks. It is stored — by
 * the backend through `IProperties`, or by {@see DeadProperties}, which also
 * carries it across `COPY` and `MOVE` as §6.2.1 asks.
 *
 * ## And one it answers since the collations exist
 *
 * **`CARDDAV:supported-collation-set`** was deliberately absent until P5-05,
 * and the reason is worth keeping: §8.3 makes it a promise with teeth — "If
 * the client chooses a collation not supported by the server, the server MUST
 * respond with a CARDDAV:supported-collation precondition error response" — so
 * a server advertising a collation it could not apply would owe a client a
 * match it could not give. {@see \DavServices\Dav\Collation\Collations} now
 * has all three R-CARD-09 names, so the list is true, and it is read from
 * there rather than written out a second time.
 *
 * ## The rule a node cannot keep
 *
 * §5.2: "Collections contained in address book collections MUST NOT contain
 * address book collections **at any depth**." Whether a path sits below an
 * address book is a question about the tree, so it is answered here — on the
 * seam that creates a collection, and on the two that move one. Without the
 * second half the rule would be a formality: a client would create the address
 * book beside and move it in.
 */
final class CardDav
{
    /**
     * What §6.1 makes the server promise in the `DAV` header.
     */
    public const COMPLIANCE = 'addressbook';

    private const NS = 'urn:ietf:params:xml:ns:carddav';

    private const QUERY = '{' . self::NS . '}addressbook-query';

    private const MULTIGET = '{' . self::NS . '}addressbook-multiget';

    private const SUPPORTED_ADDRESS_DATA = '{' . self::NS . '}supported-address-data';

    private const ADDRESS_DATA_TYPE = '{' . self::NS . '}address-data-type';

    private const MAX_RESOURCE_SIZE = '{' . self::NS . '}max-resource-size';

    private const SUPPORTED_COLLATION_SET = '{' . self::NS . '}supported-collation-set';

    private const SUPPORTED_COLLATION = '{' . self::NS . '}supported-collation';

    private const HOME_SET = '{' . self::NS . '}addressbook-home-set';

    private const PRINCIPAL_ADDRESS = '{' . self::NS . '}principal-address';

    /**
     * The precondition §6.3.2.1 defines for a `COPY` or `MOVE` that would put
     * an address book where one may not be.
     */
    private const LOCATION_OK = '{' . self::NS . '}addressbook-collection-location-ok';

    /** §6.3.2.1: the body is valid data for its media type (i.e. valid vCard). */
    private const VALID_ADDRESS_DATA = '{' . self::NS . '}valid-address-data';

    /** §6.3.2.1: no other card in the address book already holds that `UID`. */
    private const NO_UID_CONFLICT = '{' . self::NS . '}no-uid-conflict';

    /**
     * The limit the requirements fix for a card: 8 MB.
     *
     * R-CAL-08 makes every such figure configurable and this one the default.
     * §6.2.3 adds what the number means to a client: "In the absence of this
     * property, the client can assume that the server will allow storing a
     * resource of any reasonable size" — so saying nothing is not the safe
     * option, it is the vaguer one.
     */
    public const MAX_SIZE = 8_388_608;

    /** @var Closure(string): ?string */
    private readonly Closure $principalAddress;

    /**
     * @param string $addressBooksAt Where the address book homes are mounted,
     *                               as a path inside the tree; nothing in this
     *                               library assumes one, which is why it is
     *                               said here. A principal's home set is that
     *                               path with the principal's name under it
     * @param int $maxResourceSize The largest card this server will keep, in
     *                             octets (§6.2.3)
     * @param list<array{string, string}> $supportedAddressData The media types
     *                                                          and versions this server will keep, as pairs. §6.2.2: "In the
     *                                                          absence of this property, the server MUST only accept data with the
     *                                                          media type 'text/vcard' and vCard version 3.0" — so a server that
     *                                                          reads 4.0 as well has to say so, or no client will send it
     * @param (callable(string): ?string)|null $principalAddress Where a
     *                                                           principal's own card is, as a path inside the tree, asked by the
     *                                                           principal's name. §7.1.2 gives the property exactly one `DAV:href`,
     *                                                           so there is no truthful empty answer: null leaves it unanswered
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $addressBooksAt = 'addressbooks',
        private readonly int $maxResourceSize = self::MAX_SIZE,
        private readonly array $supportedAddressData = AddressData::KEPT,
        ?callable $principalAddress = null,
    ) {
        $this->principalAddress = $principalAddress === null
            ? static fn (string $principal): ?string => null
            : $principalAddress(...);
    }

    /**
     * Switches CardDAV on.
     *
     * **It announces one compliance class and owes what the word promises.**
     * §6.1: "A value of 'addressbook' in the DAV response header MUST indicate
     * that the server supports all MUST level requirements and REQUIRED
     * features specified in this document."
     *
     * @param Report $report The `REPORT` method, which this plugin owes an
     *                       `addressbook-query` (§8.6) and an
     *                       `addressbook-multiget` (§8.7). It is asked for
     *                       rather than optional for the reason the sentence
     *                       above gives
     */
    public function register(Report $report): void
    {
        $events = $this->server->events();

        $events->on(OptionsRequested::class, $this->announce(...));
        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(BeforeCreateCollection::class, $this->refuseNesting(...));
        $events->on(BeforeCopy::class, $this->refuseABadDestination(...));
        $events->on(BeforeMove::class, $this->refuseABadDestination(...));
        $events->on(BeforeCreateFile::class, $this->validateTheCard(...));
        $events->on(BeforeWriteContent::class, $this->validateTheCard(...));

        $this->answerTheReportsItOwes($report);
    }

    /**
     * The two reports §8.6 and §8.7 make REQUIRED.
     *
     * **This is why `register()` asks for the `REPORT` method** rather than
     * leaving the lines to an application: §8.6 says "Support for the
     * CARDDAV:addressbook-query REPORT is REQUIRED" and §8.7 says the same of
     * the multiget, and the compliance class announced above promises every
     * REQUIRED feature of the memo. A server that announced `addressbook` and
     * then refused one of them with `DAV:supported-report` would have told the
     * client something untrue — and an application left to remember two more
     * lines would sooner or later ship without one.
     *
     * **Both are handed the list this plugin publishes.** §6.2.2's property
     * says which media types the server keeps, and §8.6 and §8.7 make that
     * same list a precondition of both reports — so it travels from here
     * rather than being written out again where it could drift.
     */
    private function answerTheReportsItOwes(Report $report): void
    {
        $report->on(self::QUERY, (new AddressBookQuery($this->server, $this->supportedAddressData))(...));
        $report->on(self::MULTIGET, (new AddressBookMultiGet($this->server, $this->supportedAddressData))(...));
    }

    /**
     * §6.1's field in the `DAV` header.
     */
    public function announce(OptionsRequested $event): void
    {
        $event->addCompliance(self::COMPLIANCE);
    }

    /**
     * Answers whichever of the four was asked for.
     */
    public function describe(PropertiesRequested $event): void
    {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof AddressBook) {
            $this->describeTheAddressBook($result);
        }

        if ($node instanceof Principal) {
            $this->describeThePrincipal($result, $node);
        }
    }

    /**
     * **An address book inside an address book is refused**, at any depth
     * (§5.2).
     *
     * @throws Forbidden If this would be an address book below one
     */
    public function refuseNesting(BeforeCreateCollection $event): void
    {
        if (!in_array(AddressBook::ADDRESSBOOK, $event->resourceTypes(), true)) {
            return;
        }

        $this->refuseIfBelowAnAddressBook($event->path());
    }

    /**
     * **And so is moving or copying one there** (§6.3.2.1).
     *
     * The precondition is scoped by its own first words — "when the
     * Request-URI is an address book collection" — so a card moved about
     * inside an address book is no business of this rule.
     *
     * @throws Forbidden If an address book would end up below another
     */
    public function refuseABadDestination(BeforeCopy|BeforeMove $event): void
    {
        $this->checkWhatIsBeingTransferred($event);

        $moving = $this->nodeAt($event->from());

        if ($moving === null) {
            return;
        }

        // The walk is paid for only where it can matter: a destination that is
        // not below an address book takes nothing with it that §5.2 forbids,
        // whatever the source holds.
        if (!$this->isBelowAnAddressBook($event->to())) {
            return;
        }

        if (!$this->holdsAnAddressBook($moving)) {
            return;
        }

        throw new Forbidden(
            sprintf(
                'An address book may not come to rest inside the address book above "%s" (RFC 6352 §5.2).',
                $event->to(),
            ),
            self::LOCATION_OK,
        );
    }

    /**
     * The two properties the server answers for an address book because they
     * are the server's own limits (§6.2.2, §6.2.3).
     */
    private function describeTheAddressBook(PropFindResult $result): void
    {
        if ($result->wants(self::SUPPORTED_ADDRESS_DATA)) {
            $result->set(self::SUPPORTED_ADDRESS_DATA, $this->theDataItTakes());
        }

        if ($result->wants(self::MAX_RESOURCE_SIZE)) {
            $result->set(self::MAX_RESOURCE_SIZE, (string) $this->maxResourceSize);
        }

        if ($result->wants(self::SUPPORTED_COLLATION_SET)) {
            $result->set(self::SUPPORTED_COLLATION_SET, $this->theCollationsItHas());
        }
    }

    /**
     * §8.3.1's element: one `supported-collation` per identifier.
     *
     * **The list is read rather than written out**, because §8.3 makes it a
     * promise with teeth: "If the client chooses a collation not supported by
     * the server, the server MUST respond with a CARDDAV:supported-collation
     * precondition error response." A property naming a fourth collation would
     * owe a client a match the server cannot give, and a literal written twice
     * is how that happens.
     */
    private function theCollationsItHas(): Element
    {
        $property = new Element(self::SUPPORTED_COLLATION_SET);

        foreach (Collations::names() as $identifier) {
            $collation = new Element(self::SUPPORTED_COLLATION);

            $collation->appendText($identifier);
            $property->append($collation);
        }

        return $property;
    }

    /**
     * The two §7.1 calls "Additional Principal Properties".
     */
    private function describeThePrincipal(PropFindResult $result, Principal $node): void
    {
        if ($result->wants(self::HOME_SET)) {
            $result->set(self::HOME_SET, $this->holdingHrefs(
                self::HOME_SET,
                [Path::join($this->addressBooksAt, $node->name())],
                collections: true,
            ));
        }

        if (!$result->wants(self::PRINCIPAL_ADDRESS)) {
            return;
        }

        // Asked only where somebody asked for the answer: the application's
        // callable may well go looking in a directory, and a `PROPFIND` for
        // `DAV:displayname` has no business costing that.
        $card = ($this->principalAddress)($node->name());

        if ($card !== null) {
            $result->set(self::PRINCIPAL_ADDRESS, $this->holdingHrefs(self::PRINCIPAL_ADDRESS, [$card]));
        }
    }

    /**
     * §6.2.2's element: one `address-data-type` per kind, with the media type
     * and the version as attributes.
     */
    private function theDataItTakes(): Element
    {
        $property = new Element(self::SUPPORTED_ADDRESS_DATA);

        foreach ($this->supportedAddressData as [$contentType, $version]) {
            $property->append(new Element(
                self::ADDRESS_DATA_TYPE,
                ['content-type' => $contentType, 'version' => $version],
            ));
        }

        return $property;
    }

    /**
     * One property holding an href per path, because a client puts an href
     * straight into a request and only the server knows where the tree is
     * mounted.
     *
     * **A collection is named with a trailing slash.** RFC 4918 §5.2:
     * "Wherever a server produces a URL referring to a collection, the server
     * SHOULD include the trailing slash." Which of these are collections is
     * settled by the property rather than by the tree: §7.1.1 defines the home
     * set as "the URL of any WebDAV collections", so the value is a collection
     * URL whether or not anything has been mounted there yet. That is why {@see Server::hrefOf()} is not
     * asked — it answers the question from a node, and there may be no node
     * to hand it. §7.1.2's card is a resource and gets no slash.
     *
     * The slash is appended without first trimming one, because a home set's
     * path always ends in a principal's name: `href()` writes at least that
     * one segment, so it cannot already end in a separator. A guard against a
     * case that cannot arise is a line no test can reach, and a mutation run
     * is where that shows up.
     *
     * @param list<string> $paths
     */
    private function holdingHrefs(string $name, array $paths, bool $collections = false): Element
    {
        $property = new Element($name);

        foreach ($paths as $path) {
            $href = new Element('{DAV:}href');
            $url = $this->server->href($path);

            $href->appendText($collections ? $url . '/' : $url);
            $property->append($href);
        }

        return $property;
    }

    /**
     * Refuses where any collection above this path is an address book.
     *
     * @throws Forbidden If one of them is
     */
    private function refuseIfBelowAnAddressBook(string $path): void
    {
        if ($this->isBelowAnAddressBook($path)) {
            throw new Forbidden(
                sprintf('An address book may not be created below one (RFC 6352 §5.2): "%s".', $path),
            );
        }
    }

    /**
     * Whether an address book stands anywhere above this path.
     *
     * The walk goes upwards rather than downwards because that is the question
     * §5.2 asks from this side: "at any depth" below an address book is the
     * same thing as "with an address book anywhere above".
     */
    private function isBelowAnAddressBook(string $path): bool
    {
        return $this->bookOver($path) !== null;
    }

    /**
     * Whether this node is an address book or has one somewhere inside it.
     *
     * **The "somewhere inside" half is what makes §5.2(b) more than a
     * formality.** Refusing only what is itself an address book would leave
     * the rule open by one step: a client would put the address book in a
     * folder and move the folder.
     */
    private function holdsAnAddressBook(INode $node): bool
    {
        if ($node instanceof AddressBook) {
            return true;
        }

        if (!$node instanceof ICollection) {
            return false;
        }

        foreach ($node->children() as $child) {
            if ($this->holdsAnAddressBook($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §6.3.2.1's preconditions, for a card on its way into an address book.
     *
     * **A file outside an address book is nobody's business here.** The
     * section applies "when a PUT operation of an address object resource into
     * an address book collection occurs" — a plain WebDAV collection takes
     * whatever a client puts in it, and a CardDAV plugin that validated
     * everything would turn a WebDAV server into a card store.
     *
     * @throws Forbidden If the body is no single valid vCard, or its `UID` is
     *                   already in use in that address book
     * @throws UnsupportedMediaType If its version is not one this server keeps
     * @throws PayloadTooLarge If it is larger than the limit
     */
    public function validateTheCard(BeforeCreateFile|BeforeWriteContent $event): void
    {
        $book = $this->bookOver($event->path());

        if ($book === null) {
            return;
        }

        [$where, $collection] = $book;

        $this->mustTake(self::bytesIn($event->content(), $event), $where, $collection, $event->path());
    }

    /**
     * The same preconditions for the other two methods §6.3.2.1 names.
     *
     * **A collection is not an address object**, and the section applies to
     * "a COPY or MOVE operation of an address object resource" — so a folder
     * being moved in is left to §5.2's half above, which is the rule that is
     * about collections.
     *
     * **A `MOVE` does not conflict with itself.** The card at the source is on
     * its way out, so counting it would make renaming a card impossible — and
     * §6.3.2 says the name is free: "The URL for each address object resource
     * is entirely arbitrary". A `COPY` leaves the source where it is, so there
     * it is a conflict like any other.
     *
     * @throws Forbidden If the card is no single valid vCard, or its `UID` is
     *                   already in use at the destination
     * @throws UnsupportedMediaType If its version is not one this server keeps
     * @throws PayloadTooLarge If it is larger than the limit
     */
    private function checkWhatIsBeingTransferred(BeforeCopy|BeforeMove $event): void
    {
        $book = $this->bookOver($event->to());

        if ($book === null) {
            return;
        }

        $source = $this->nodeAt($event->from());

        if (!$source instanceof IFile) {
            return;
        }

        [$where, $collection] = $book;

        // The card is read through `get()`, and read once: every store in this
        // library opens afresh on each call, so the transfer that follows gets
        // its own handle.
        $this->mustTake(
            self::bytesIn($source->get()),
            $where,
            $collection,
            $event->to(),
            $event instanceof BeforeMove ? $event->from() : null,
        );
    }

    /**
     * Holds a card to §6.3.2.1's four preconditions.
     *
     * **The size is asked first**, because it is the one question that needs
     * no parsing: a body far too large is refused before anything reads it.
     * The memo puts the preconditions in no order, so this one is chosen.
     *
     * @param string $book The path of the address book it is going into
     * @param string $path Where the card itself will be, which is not a
     *                     conflict with itself
     * @param string|null $leaving A path on its way out, which is not a
     *                             conflict either — a `MOVE`'s source
     *
     * @throws Forbidden If the body is no single valid vCard, or its `UID` is
     *                   already in use in that address book
     * @throws UnsupportedMediaType If its version is not one this server keeps
     * @throws PayloadTooLarge If it is larger than the limit
     */
    private function mustTake(
        string $bytes,
        string $book,
        AddressBook $collection,
        string $path,
        ?string $leaving = null,
    ): void {
        if (strlen($bytes) > $this->maxResourceSize) {
            throw new PayloadTooLarge(
                sprintf(
                    'An address object is at most %d octets here: RFC 6352 §6.2.3, and the CARDDAV:max-resource-size precondition of §6.3.2.1.',
                    $this->maxResourceSize,
                ),
                self::MAX_RESOURCE_SIZE,
            );
        }

        $card = AddressObject::in($bytes) ?? throw new Forbidden(
            'An address object holds a single vCard component only: RFC 6352 §5.1, and the CARDDAV:valid-address-data precondition of §6.3.2.1.',
            self::VALID_ADDRESS_DATA,
        );

        $version = $card->property('VERSION')?->value();
        $uid = $card->property('UID')?->value();

        if ($version === null) {
            // RFC 6350 §6.7.9 makes VERSION mandatory — "This property MUST be
            // present in the vCard object" — so a card without one is not
            // valid vCard data, whatever else it holds.
            throw new Forbidden(
                'A vCard says which version it is written to: RFC 6350 §6.7.9 makes VERSION mandatory.',
                self::VALID_ADDRESS_DATA,
            );
        }

        if ($uid === null || $uid === '') {
            // §5.1 asks for more than vCard does: "vCard components in an
            // address book collection MUST have a UID property value". Without
            // one the uniqueness rule in the same sentence has nothing to hold.
            throw new Forbidden(
                'A card in an address book has a UID: RFC 6352 §5.1 makes it mandatory even where RFC 6350 does not.',
                self::VALID_ADDRESS_DATA,
            );
        }

        $this->mustBeAVersionItKeeps($version);

        $taken = $this->whoAlreadyHas($uid, $book, $collection, $path, $leaving);

        if ($taken !== null) {
            throw new Forbidden(
                sprintf('The UID "%s" is already in use in this address book (RFC 6352 §5.1).', $uid),
                $this->holdingHrefs(self::NO_UID_CONFLICT, [$taken]),
            );
        }
    }

    /**
     * **The version is part of the media type**, which is why an unadvertised
     * one is `CARDDAV:supported-address-data` rather than a validity problem:
     * §6.2.2's `address-data-type` carries a `content-type` **and** a
     * `version`, and §5.1.1 calls the pair a media type. A `415` says the same
     * thing in the status line.
     *
     * @throws UnsupportedMediaType If this server never advertised it
     */
    private function mustBeAVersionItKeeps(string $version): void
    {
        foreach ($this->supportedAddressData as [, $kept]) {
            if ($kept === $version) {
                return;
            }
        }

        throw new UnsupportedMediaType(
            sprintf(
                'This server does not keep vCard %s; CARDDAV:supported-address-data says which versions it keeps (RFC 6352 §6.2.2).',
                $version,
            ),
            self::SUPPORTED_ADDRESS_DATA,
        );
    }

    /**
     * The path of the card in that book already holding this `UID`, or null.
     *
     * **The scope is the whole address book**, subfolders included: §5.1 asks
     * for a `UID` "unique in the scope of the address book collection in which
     * it is contained", and §5.2 lets a book hold "collections that are not
     * address book collections" — so the walk goes down.
     *
     * **It walks the collection rather than what a client may see.** A card
     * concealed from this client still holds its `UID`; a guard that asked
     * {@see \DavServices\Dav\VisibleMembers} instead would let two people
     * create the same `UID` and leave the store with two.
     */
    private function whoAlreadyHas(
        string $uid,
        string $book,
        ICollection $collection,
        string $path,
        ?string $leaving,
    ): ?string {
        foreach ($collection->children() as $child) {
            $where = Path::join($book, $child->name());

            if ($child instanceof ICollection) {
                $found = $this->whoAlreadyHas($uid, $where, $child, $path, $leaving);

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if ($where === $path || $where === $leaving) {
                continue;
            }

            if (AddressObject::of($child)?->property('UID')?->value() === $uid) {
                return $where;
            }
        }

        return null;
    }

    /**
     * The address book a path lies in, with its own path, or null where there
     * is none above it.
     *
     * §5.2 forbids an address book inside an address book, so the first one
     * upwards is the only one there can be.
     *
     * @return array{string, AddressBook}|null
     */
    private function bookOver(string $path): ?array
    {
        [$parent] = Path::split($path);

        while ($parent !== '') {
            $node = $this->nodeAt($parent);

            if ($node instanceof AddressBook) {
                return [$parent, $node];
            }

            [$parent] = Path::split($parent);
        }

        return null;
    }

    /**
     * Content as bytes, whichever of the two shapes it arrived in.
     *
     * **A stream that is read has to be put back.** `BeforeCreateFile` says
     * what a listener owes: "A listener that means to look at it has to put
     * back something the write can use […] reading the stream without
     * replacing it is a way to store an empty file." So where an event is
     * handed in, what was read is written back to it.
     *
     * @param resource|string $content
     */
    private static function bytesIn(mixed $content, BeforeCreateFile|BeforeWriteContent|null $event = null): string
    {
        if (is_string($content)) {
            return $content;
        }

        $bytes = (string) stream_get_contents($content);

        $event?->writeInstead($bytes);

        return $bytes;
    }

    /**
     * The node at a path, or null where nothing is there.
     *
     * **A path that is not in the tree is no business of this plugin.** A
     * `MOVE` from a source that is not there is a `404` the method raises, and
     * a plugin that fell over looking for it would turn that into a `500`.
     */
    private function nodeAt(string $path): ?INode
    {
        try {
            return $this->server->tree()->node($path);
        } catch (NotFound) {
            return null;
        }
    }
}
