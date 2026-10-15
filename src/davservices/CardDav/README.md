# CardDav

Address books, after RFC 6352. This layer sits above the backends and below
the plugins: it may use the tree, the events, a backend and `VObject`, and
nothing below it may use it.

**An address book is an ordinary WebDAV collection with one thing said about
it.** RFC 6352 §5.2:

> An address book collection MUST report the DAV:collection and
> CARDDAV:addressbook XML elements in the value of the DAV:resourcetype
> property.

That sentence is the whole of the data model. Everything else CardDAV
adds — the properties, the reports, the preconditions — hangs off resources
that are already WebDAV resources, which is why so little is needed here.

## Classes

| Class | Purpose |
|---|---|
| `AddressBook` | Says of a collection that it is an address book, and keeps §5.2's rule about what may be inside |
| `AddressBookKeepingItsOwn` | The same, over a store that keeps properties of its own — chosen by `AddressBook::of()`, never constructed by hand |
| `AddressObject` | Whether a node is a card, and which card — §5.1's "a single vCard component only", asked once for both reports |
| `AddressData` | Which parts of a card a report returns, and in which version (§10.4) |
| `PropertyName` | A vCard property as a client names it, group prefix and all — the rule §10.5.1 and §10.4.2 state word for word |
| `Filter\*` | What a client is searching for: §10.5's `filter` down to §10.5.4's `text-match` |
| `Report\AddressBookQuery` | `CARDDAV:addressbook-query` (§8.6), including §8.6.2's truncation |
| `Report\AddressBookMultiGet` | `CARDDAV:addressbook-multiget` (§8.7): these cards, by name |
| `Report\WhatToReturn` | The `DAV:response` both reports write about one card, read from the same property group |

## Using it

```php
$home->add(AddressBook::of($yourCollection));

(new CardDav($server, 'addressbooks'))->register($report);
```

**The cards stay where they already are.** `AddressBook` wraps a collection
rather than being one, because where the cards live is the application's
business — a directory on disc, a table, a directory service — and a library
that insisted on storage of its own would be useful only to applications that
had none. Everything but the resource type is the collection underneath,
including its properties: `CARDDAV:addressbook-description` is stored rather
than computed (§6.2.1 says it "SHOULD NOT be protected so that users can
specify a description"), so a wrapper that answered properties itself would
hide whatever the store keeps.

**`AddressBook::of()` rather than `new`, and the reason is worth knowing.**
`IProperties` is a claim, not a convenience: `Plugin\DeadProperties` steps
aside for any node that implements it, because the contract is that such a node
keeps all of its properties itself. An address book that claimed the interface
over a store keeping none would therefore take dead property storage away from
every address book in the server — silently, and from
`CARDDAV:addressbook-description` first. So the factory claims it only where
there is a store behind it that does, by handing back
`AddressBookKeepingItsOwn` instead.

The second line is `Plugin\CardDav`, and it is what makes the server a CardDAV
server rather than a WebDAV server with some collections in it: §6.1 makes the
`DAV` response header the promise, and a client that does not read
`addressbook` there never looks for an address book at all.

## What may be inside an address book

§5.2 puts two restrictions on the contents, and the second is the one with
teeth:

- "Address book collections MUST only contain address object resources and
  collections that are **not** address book collections."
- "Collections contained in address book collections MUST NOT contain address
  book collections **at any depth**."

So **a plain collection inside an address book is allowed** — the memo says so
in as many words, and refusing it would be stricter than the specification.
What is not allowed is an address book, anywhere below one.

The rule is kept in two places because it is two questions. `AddressBook` is
deliberately **not** an `IExtendedCollection`: an extended `MKCOL` is how an
address book is made (§6.3.1), so a collection offering it inside an address
book would offer exactly what §5.2 forbids. Leaving the interface off makes
`Dav\Method\MkCol` answer with RFC 5689's own `DAV:valid-resourcetype` and name
the property it refused, where implementing it in order to throw would turn
that into a bare `403` with nothing in it a client can read.

Whether a path sits below an address book **at all** is a question about the
tree rather than about a node, and a node knows its name and nothing about
where it hangs. `Plugin\CardDav` answers that one — on the seam that creates a
collection, and on the two that move one. Without the second half the rule
would be a formality: a client would create the address book beside and move
it in.

And the move guard asks what the thing being moved **holds**, not only what it
is, because §5.2(b) says "at any depth": refusing only what is itself an
address book would leave the rule open by one step, with the client putting the
address book in a folder and moving the folder. The walk into the source is
paid for only where the destination is below an address book, which is the one
case it can matter in.
