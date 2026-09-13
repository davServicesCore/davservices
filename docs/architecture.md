# Architecture

Layers, the tree model, the event system, and where the seams are.

> **Status:** written as far as the library is built. Everything described here
> exists and is covered by tests; what is planned but absent is marked as such.

## Contents

- [The shape of a request](#the-shape-of-a-request)
- [Layers](#layers)
- [The tree](#the-tree)
- [Events](#events)
- [XML](#xml)
- [Failures](#failures)
- [Seams](#seams)
- [What this library does not do](#what-this-library-does-not-do)

## The shape of a request

One request goes through the library like this:

```
$_SERVER ─▶ Sapi ─▶ Request ─▶ Server ─▶ plugins ─▶ Tree ─▶ your backend
                                  │
                                  ▼
             Sapi ◀── Response ◀── the method, or a plugin that answered first
```

`Sapi` is the only class that knows `$_SERVER`, `php://input` and `header()`
exist. Everything above it deals in `Request` and `Response`, which is why the
whole of it can be tested without a web server: hand `Sapi` an array and a
stream, take back the bytes it would have written.

## Layers

Dependencies run one way only, and `bin/check-layers.php` fails the build when
they do not. Lowest first:

| Layer | Holds | Depends on |
|---|---|---|
| `Event` | The emitter plugins take part through | nothing |
| `Uri` | Paths: decoding, normalising, refusing | nothing |
| `Http` | Request, response, headers, body, ranges, conditions, the WebDAV `If` header | `Uri` |
| `Xml` | Reading and writing the documents a request is made of | `Http` |
| `Dav` | The node interfaces and the tree | may use any of the above |
| `Acl`, `CalDav`, `CardDav`, `Plugin`, `Backend` | *planned* | may use any of the above |

`Exception` sits outside the order on purpose: a failure belongs to no layer,
and every layer raises one.

Two consequences worth knowing before you extend anything. A class in `Http`
cannot reach for `Dav`, so the HTTP layer can be used on its own — which is
what makes it testable in isolation. And a protocol extension you write lives
above `Dav`, never inside it.

## The tree

A DAV server serves a tree of nodes. `Dav\Tree` is the only thing that turns a
path into one:

```php
$tree = new Tree($root);

$tree->node('calendars/alice/work.ics');   // the node, or NotFound
$tree->exists('calendars/alice');          // a question, never a refusal
$tree->forget('calendars/alice');          // after a change; drops what is below it too
```

**A node knows its own name and nothing about where it hangs.** The path is the
tree's business. That is what lets the same node appear in more than one place
without knowing it, and lets your backend hand one over without first working
out how the server got there.

**The tree walks down one member at a time**, so a backend never has to build
more of itself than a request asks for, and **every node it passes is kept for
the length of the request**. That second part is not a nicety: a `PROPFIND`
with `Depth: 1` on a collection of two hundred members asks for every one of
them, and each plugin with something to say asks again while the answer is
built. Tell the tree with `forget()` whenever you change something — it drops
the path and everything below it.

The interfaces a backend implements are in [writing a backend](backends.md).
The short version: `INode` for anything addressable, `IFile` for content,
`ICollection` for members, and a handful of optional ones a node implements
when it can do something better than the server could on its own —
`IMultiGet`, `IFilter`, `IQuota`, `IMoveTarget`, `ICopyTarget`.

**Content moves as a stream wherever it can.** `IFile::get()` may return one and
`put()` may be given one. A file of any size then costs the same; reading a
recording into a string to hand it over spends as much memory as the recording
is long.

## Events

Plugins take part through an emitter. The emitter is here today; the events the
server emits — `beforeMethod`, `propFind`, `beforeBind` and the rest of
R-ARC-04 — arrive with the server itself in the next phase, and a listener for
one of them will read like this:

```php
$emitter->on(BeforeMethod::class, static function (BeforeMethod $event): void {
    $event->stop();          // answered here; the server's own handler will not run
}, priority: 10);
```

Three things follow from how it works:

- **The lower priority runs first**, and listeners that name the same priority
  run in the order they were registered. Two plugins asking for the same place
  is the ordinary case, and a server whose behaviour depended on how PHP sorted
  them would be one nobody could reason about.
- **A stopped event ends the chain.** That is how a plugin *replaces* the
  default handling of a method rather than merely running before it.
- **Events are objects**, not arrays with a payload: named and typed
  properties, a listener that can be given the class it listens for, and a
  change a static analyser can follow through every plugin that listens.

There is no static state anywhere in the library, so one process may hold as
many servers as it likes — which is what lets the test suite build hundreds.

## XML

Requests are read with `XMLReader`, never with `simplexml`. The reader is the
most exposed class here: it is handed whatever an unauthenticated stranger
cared to send, before anybody has been asked to log in.

| It refuses | Because |
|---|---|
| Any document type declaration | It is how `file:///etc/passwd` ends up in a response, and how ten nested entities turn one line into three gigabytes |
| Documents past a configurable size, depth or element count | A refusal must be a `400`, never a memory error |
| Anything not well formed | With the line number, which is the one thing this library knows that a client developer does not |

Element names carry their namespace — `{DAV:}propfind`,
`{urn:ietf:params:xml:ns:caldav}calendar-query` — because a prefix means
nothing: `D:propfind`, `d:propfind` and `propfind` under a default namespace are
one element.

What an element *means* is registered by name in `ElementRegistry`, and the two
directions are deliberately not symmetric. **Reading tolerates the unknown**: an
element nobody registered a reader for comes back as it stands, since a client
asking for a property this server never heard of is an ordinary Tuesday.
**Writing does not**: a value nobody registered a writer for is refused, because
something invented in its place would go out on the wire as though it had been
meant.

On the way out, `Writer` escapes everything and declares every namespace at the
root — a `PROPFIND` answer would otherwise repeat its declarations on each of
two hundred responses, and more than one client in the wild reads only what the
root declares. `MultiStatus` builds the `207` of RFC 4918 §13 in one place,
because its shape is the part of WebDAV that implementations get wrong most
often.

## Failures

Everything the server refuses is an exception carrying the status it is
reported with:

```php
throw new Forbidden('Depth: infinity is disabled.', '{DAV:}propfind-finite-depth');
```

The second argument is the precondition of RFC 4918 §16 that goes into the
`DAV:error` body. It belongs to the throw site rather than to the class,
because one status serves several: a `403` is `propfind-finite-depth` in one
place and `need-privileges` in another.

`IHttpFailure` is what the server catches: the status, and the precondition if
there is one. The lower layers raise refusals of their own — a malformed path,
a header that could forge another — and those extend the SPL types, so a caller
who has never heard of this library still catches them.

## Seams

Four places are meant to be taken over from outside:

| Seam | What you provide |
|---|---|
| **Backends** | The nodes themselves: `INode`, `IFile`, `ICollection`, and the optional interfaces your storage can answer better than the server can |
| **Plugins** | Listeners on the emitter; a plugin that answers a request stops the event, and the server's own handling does not run |
| **The element registry** | What an element of your namespace means, in both directions |
| **The SAPI** | Where a request comes from and where a response goes, if not `$_SERVER` and `php://output` |

Everything else — the methods, the property handling, the reports — is the
library's own business.

## What this library does not do

- **It is not a web server.** It runs under whatever SAPI you have, and holds
  no opinion about how the request reached you.
- **It is not a client.** There is no code here for talking to another DAV
  server.
- **It has no runtime dependencies.** Not one, by design and by a build gate:
  PHP and its extensions, nothing else. Development tools are another matter.
- **It knows nothing of users, tenants or permissions models.** Access control
  is a seam (`IPrivilegeResolver`, planned), not a policy. What a user is, and
  who may see what, belongs to the application embedding this library.
