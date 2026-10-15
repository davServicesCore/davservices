# Plugin

What the server can do beyond plain WebDAV, and nothing it has to. Every plugin
here is listeners on the emitter (R-ARC-02): the methods know nothing about
them, and an application that wants none of them registers none.

This is the highest layer. A plugin may use anything below it — the tree, the
events, a backend — and nothing below may use a plugin.

## Classes

| Class | Purpose |
|---|---|
| `DeadProperties` | Keeps the properties the server does not understand, in a storage backend |
| `Locks` | `LOCK` and `UNLOCK`, and what a client is told about a hold |
| `Principals` | Where the people are, who you are, and what a principal's own URL is |
| `Acl` | What a client is told about access control: the privilege tree, what the asker may do, and who holds what |
| `CardDav` | What a client is told about address books, and the one rule RFC 6352 §5.2 cannot leave to a node |
| `Browser` | Shows what is in a collection, for development only — off unless it is switched on |

## Using it

```php
$properties = new DeadProperties(new PropertyStorage('/var/lib/davservices/properties'));
$properties->registerOn($server->events());
```

A plugin knows which seams it needs; an application should not have to. That is
what `registerOn()` is for — `DeadProperties` listens on three of them, and the
line above is the whole of what an application writes.

## Locking is a plugin, and that is a promise

```php
(new Locks($server, new LockBackend('/var/lib/davservices/locks')))->register();
```

That line is the difference between `DAV: 1` and `DAV: 1, 2`. Without it a
`LOCK` is a `501` and the server is a plain WebDAV server rather than a broken
one — which is what R-LOCK-06 asks for, and why the first test of the plugin is
the one that proves it.

`register()` takes no argument because the plugin was handed the server when it
was made: it brings two **methods** as well as listeners, and a method has to be
registered with the server rather than with the emitter.

The third argument is the longest a lock is handed out for, whatever a client
asks (R-LOCK-03). An hour is the default. `Timeout: Infinite` is answered with
that maximum rather than with forever: a lock that never ends is one nobody can
clear after a client has crashed.

**The same plugin refuses the writes a lock stands in the way of.** Two
answers, and keeping them apart is the whole of it:

- **`423 Locked`** — the resource is held and the request submitted no token
  for it. The client claimed nothing; it simply may not write. The body names
  `DAV:lock-token-submitted`, which tells a client that a token is what is
  missing.
- **`412 Precondition Failed`** — the request put a condition on itself in an
  `If` header, and the condition does not hold. This is owed even when nothing
  is locked at all: `If: (<opaquelocktoken:made-up>)` on a free resource is a
  claim that is simply false.

Nothing of that reaches into a method class. Every refusal hangs on a seam
that was already there — `BeforeWriteContent`, `BeforeBind`, `BeforeUnbind`,
`BeforeCopy`, `BeforeMove`, and `PropertiesChanging` for `PROPPATCH`, because
a write lock holds the dead properties too (RFC 4918 §7.5). If a write could
not be caught, the answer would be a missing seam rather than a special case
inside the method.

**A read is not guarded**, and a read that puts a condition on itself still
is: `If` guards the request, not only the write.

**Without these two lines the server is plain WebDAV**, and that is tested
rather than asserted: `tests/unit/Dav/PlainWebDavServerTest.php` assembles a
server with nothing about locks in it and holds the library to R-LOCK-06 — it
says `DAV: 1`, answers `501` to `LOCK` and `UNLOCK`, lets every write through,
and answers `404` for the two lock properties rather than an empty element
that would claim nobody holds the resource.

**The `If` header is evaluated by the core, not by this plugin.** RFC 4918
§10.4 is core WebDAV: a header of entity tags alone needs no lock storage to
check, and a plain server that ignored one would drop a guard its client took
pains to set. So the evaluation lives in `Dav\Precondition\RequestConditions`,
and all this plugin contributes is the answer to one question —
`Dav\Event\StateTokensRequested`: which tokens is this path in the state of?

The difference from a method is what decides that. A method nobody registers
is one the server refuses honestly with `501`. A condition nobody evaluates is
one the server silently pretends to have honoured. The first may be a plugin;
the second may not.

## Three properties nothing else can answer

```php
$root->add(new PrincipalCollection('principals', $principals));

(new Principals($server, 'principals'))->register();
```

`DAV:principal-URL`, `DAV:principal-collection-set` and
`DAV:current-user-principal` are each about **where** something is rather than
what it is, and a node knows its name and nothing about where it hangs. So a
plugin answers them: the same principal mounted at `/dav/` has a different
URL, and a node that guessed would send clients where nothing answers.

Who is signed in comes from the application, because authentication is not
this plugin's business:

```php
(new Principals($server, 'principals', static fn (Request $request): ?string
    => $yourSession->principalPath()))->register();
```

With nobody signed in the answer is `DAV:unauthenticated`, which is what
RFC 5397 gives for exactly that case. **Guessing at a principal would be worse
than saying nothing** — a client that believed it was somebody would show that
person's calendars.

## What a client is told about access control

```php
(new Acl($server, new MemoizingPrivilegeResolver($yourResolver)))->register();
```

Five properties of RFC 3744 §5, and each is easy to get wrong in a way that
looks right. `DAV:supported-privilege-set` is the tree **written as a tree**,
with the description and the language the DTD requires — a flat list would
tell a client that `DAV:write` and `DAV:bind` are unrelated.
`DAV:current-user-privilege-set` is everything the asker may do, aggregates
and all, because a client greys out its buttons from it. `DAV:acl` is the
other direction and reports the entries **as somebody wrote them**.
`DAV:inherited-acl-set` is empty rather than missing: nothing here inherits a
list, and a `404` would say the server does not know the question.

**And it refuses what may not be done** (R-ACL-05). Fail closed: what was not
granted is refused, because a server that let a request through for want of a
rule would be a server whose rules are a suggestion. Nothing reaches into a
method class — every check hangs on a seam that was already there.

**A `LOCK` is guarded although it writes nothing.** Without that, a client
that may not change a file could still take a lock on it and stop everybody
who may — a denial of service in three lines of curl. What it needs depends
on what is there: `DAV:write-content` for a resource that exists,
`DAV:bind` for one it would create (RFC 4918 §9.10.4), which the bind seam
asks for anyway. **`UNLOCK` needs nothing:** RFC 3744 §3.5 gives
`DAV:unlock` for breaking somebody else's lock, and this server has no way
to break one — it only ever removes a lock whose token was submitted, so the
token is the proof.

**A `MOVE` needs `DAV:unbind` where it came from**, and it needs a rule of
its own to get it: a move is deliberately neither a removal nor a creation
here, so nothing raises the unbind seam for the source. Without it a client
with `DAV:bind` on the destination could empty a collection it has no rights
in — the file would simply be somewhere else.

Two things are worth knowing before switching it on. **`DAV:bind` and
`DAV:unbind` belong to the collection**, not to the member (§3.9, §3.10):
creating a file is a change to the collection it appears in. And **hiding is
two things** — refusing to read a resource is half of it, the other half is
that the listing of its parent must not name it, or the client has been told
it exists:

```php
(new Acl($server, $resolver, unreadableIsNotFound: true))->register();
```

`403` says "not for you", `404` says nothing at all. Which is right depends on
whether the existence of the resource is itself a secret. **Only a refusal to
read is ever hidden**: a write that was refused is a `403` whatever the
setting, because the client plainly knows the resource is there — it is
writing to it.

`DAV:owner` and `DAV:group` are deliberately not answered here. Who owns a
resource is the backend's to say through `IProperties`, the same way
`DAV:displayname` is — a plugin that invented an owner would be inventing a
fact about somebody else's data.

## Who is asking is one question with one answer

```php
$server->events()->on(
    CurrentPrincipalRequested::class,
    static fn (CurrentPrincipalRequested $event) => $event->answerWith($yourSession->principalPath()),
);
```

`current-user-principal` says who you are, `current-user-privilege-set` says
what you may do, and the access checks decide whether you may. **Asking each
of them to keep its own idea of who is signed in would be asking them to
disagree**, and a server whose answers disagree about identity shows one
person's calendar under another person's name.

`Principals` answers the event from the callable it was given, so an
application with only that plugin writes nothing extra. A plugin that
authenticates answers the same event instead. Two listeners naming **different**
principals is a mistake in the wiring and is said out loud rather than settled
by whichever was registered first.

## One line makes it a CardDAV server

```php
(new CardDav($server, 'addressbooks'))->register($report);
```

RFC 6352 §6.1 makes the `DAV` response header the promise, and the word means
everything: "A value of 'addressbook' in the DAV response header MUST indicate
that the server supports all MUST level requirements and REQUIRED features
specified in this document." Without the line the server is plain WebDAV and a
client never looks for an address book at all.

**Four properties, and a plugin answers them for two different reasons.** Two
are limits the *server* sets rather than data a store holds —
`CARDDAV:supported-address-data` (§6.2.2) and `CARDDAV:max-resource-size`
(§6.2.3), both "MUST be protected as it indicates the level of support provided
by the server" in the memo's own words. Two are about **where** something is —
`CARDDAV:addressbook-home-set` (§7.1.1) and `CARDDAV:principal-address`
(§7.1.2) hang on a principal, and a node knows its name and nothing about where
it hangs.

Both limits are the application's to set, because R-CAL-08 asks that of every
one of them:

```php
(new CardDav($server, 'addressbooks', maxResourceSize: 1_048_576))->register($report);
```

§6.2.2 is worth reading before shortening the list of versions: "In the absence
of this property, the server MUST only accept data with the media type
'text/vcard' and vCard version 3.0." Saying nothing is not the permissive
option.

**One property it deliberately does not answer.**
`CARDDAV:addressbook-description` "SHOULD NOT be protected so that users can
specify a description" (§6.2.1), so a plugin answering it would make it
unwritable; it is stored by the backend or by `DeadProperties`, which also
carries it across `COPY` and `MOVE` as §6.2.1 asks.

**And one it answers since the collations exist.**
`CARDDAV:supported-collation-set` is owed "on any resource that supports reports
that use collations" (§8.3). Until P5-05 there were no collations to name and no
report using one, and advertising three would have been a promise §8.3 then
obliges the server to keep — "If the client chooses a collation not supported by
the server, the server MUST respond with a CARDDAV:supported-collation
precondition error response". Now `Dav\Collation` has all three, a
`CARDDAV:text-match` resolves through them, and the list is read from there
rather than written out twice.

**It also wires the two reports §8.6 and §8.7 make REQUIRED**, which is why
`register()` takes the `REPORT` method: the compliance class it announces
promises every REQUIRED feature of the memo, and a server that announced
`addressbook` and then refused `addressbook-query` with `DAV:supported-report`
would have said something untrue. Both reports are handed the same
`supported-address-data` list the property publishes, because §8.6 and §8.7 make
it a precondition of both.

**It also keeps the rule no node can.** §5.2 forbids an address book below an
address book at any depth, which is a question about the tree; the plugin
refuses it on the seam that creates a collection, and on `COPY` and `MOVE` with
the precondition §6.3.2.1 defines for exactly that —
`CARDDAV:addressbook-collection-location-ok`. Without the second half the rule
would be a formality: a client would create the address book beside and move it
in.

## The browser is not a web interface

```php
$browser = Browser::forDevelopment($server);
$server->events()->on(BeforeMethod::class, $browser(...));
```

R-DAV-10 asks for a directory browser as a **development aid**, and asks as
plainly that it never be switched on in production: a server that did would be
publishing the shape of every account to anyone who could reach the path. The
factory is named the way it is so that the warning stands in the line somebody
writes, not only in a docblock they may never open — and the page itself says
what it is, for whoever ends up looking at one.

Until those two lines are written, a `GET` on a collection is the `405` plain
WebDAV gives.

## Writing another

A plugin is an ordinary class. It needs no base class and no registration
anywhere but the emitter, and it can be tested by handing it an event rather
than by standing up a server.
