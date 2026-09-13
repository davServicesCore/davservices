# Dav

The tree a DAV server serves: what a node is, and how a path becomes one.

Everything a backend implements starts here. The interfaces say what the server
may ask of a node; the `Tree` is the only thing that turns a path into one.

## Classes

| Class | Purpose |
|---|---|
| `Server` | Takes a request and answers it: the chain, the method, and what a failure becomes |
| `Event\BeforeMethod` | Raised before the method; a listener that answers takes it over |
| `Event\AfterMethod` | Raised with the answer; a listener may hand back another |
| `Method\Options` | Answers `OPTIONS`: what the server is, and what it will do |
| `Method\Get` | Answers `GET` and `HEAD`: a file, or part of one |
| `Method\Put` | Answers `PUT`: the body becomes the content of a file |
| `Event\BeforeCreateFile`, `BeforeWriteContent` | Where a plugin checks or changes what is about to be written |
| `Event\AfterCreateFile`, `AfterWriteContent` | Where a plugin does its own bookkeeping afterwards |
| `Event\ExceptionRaised` | Raised when something went wrong, so it can be logged or answered better |
| `Tree` | Walks a path down to its node, and keeps what it found for the length of the request |
| `INode` | Anything addressable by a path: a name, a modification time, and removal |
| `IFile` | A node that holds content — as a stream wherever the backend can manage one |
| `ICollection` | A node that holds other nodes |
| `IExtendedCollection` | One that can create members of another kind (extended `MKCOL`, `MKCALENDAR`) |
| `IProperties` | A node that keeps properties of its own |
| `IMoveTarget` / `ICopyTarget` | A collection that can take a node over itself rather than node by node |
| `IQuota` | A collection that can say how much room there is (RFC 4331) |
| `IMultiGet` | A collection that can fetch several members in one go |
| `IFilter` / `IQueryFilter` | A collection that can do a report's filtering itself |

## Entry point

```php
$server = new Server(new Tree($root));

$server->onMethod('GET', $get);        // every method of WebDAV is a handler
$response = $server->handle($request); // nothing thrown reaches the caller
```

A server with nothing registered is still a working server: it answers `501`
to everything, which is the truth about one that has been given no methods
(R-ARC-02). Methods arrive as handlers, protocol extensions as listeners.

## Where the server is mounted

```php
$server = new Server($tree, baseUri: '/dav/');

$server->path($request);   // '/dav/calendars/alice' -> 'calendars/alice'
```

The tree knows nothing of the mount point: a node's path is its path inside the
tree, wherever the tree hangs. A target outside the mount is a `404` rather than
a `400` — the path is perfectly well formed, there is simply nothing of ours
there, and `400` would tell a prober that the shape of the path was the problem.

The slash matters: a server at `/dav` does not serve `/davos`.

## What a failure becomes

| Thrown | Answered with |
|---|---|
| Anything carrying `IHttpFailure` | Its own status, and a `DAV:error` body naming its precondition where it has one |
| `MalformedPath`, `MalformedHeader`, `MalformedRequest` | `400` — the client sent something that cannot be made sense of |
| Anything else | `500` **with nothing in it** |

The last row is the one that matters. The message of an unexpected failure
names paths, queries and versions; a client that received it would learn about
the inside of a server it has no business knowing. It goes to the listeners
instead, which is where an application logs it — the library itself holds no
opinion about logging.

A listener on `ExceptionRaised` may answer instead, which is how a plugin turns
its own backend's failures into the `502` or `507` they really are. One that
fails while answering is ignored: the client is still owed an answer to the
failure that came first.

## The tree

```php
$tree = new Tree($root);

$tree->node('calendars/alice/work.ics');   // the node, or NotFound
$tree->exists('calendars/alice');          // a question, never a refusal
$tree->forget('calendars/alice');          // after a change; drops what is below it too
```

## Why a node knows no path

A node knows its own name and nothing about where it hangs. The path is the
tree's business — which is what lets the same node appear in more than one
place without knowing it, and a backend hand out a node without first working
out how the server got there.

## Why the cache matters

A `PROPFIND` with `Depth: 1` on a collection of two hundred members asks for
every one of them, and every plugin with something to say about a node asks for
it again while the answer is built. Without the cache the backend sees each of
those, and what should be one query becomes hundreds (R-TREE-05).

What is cached has to be forgotten at the right moment: a node handed out after
it was deleted is worse than one that was never cached. `forget()` drops a path
and everything below it — and only what is below it, since `alice2` is not
inside `alice`.

## Streams, not strings

`IFile::get()` may return a stream and `put()` may be given one (R-TREE-02,
R-TREE-03). A file of any size then costs the same: reading a recording into a
string to hand it over would spend as much memory as the recording is long.
Callers must cope with both, and the server streams whichever it is given.

## Boundaries

The methods — `GET`, `PROPFIND`, `MOVE` — and the properties they read belong
to the server and its plugins, which sit above this directory. A node answers
questions about itself; it does not know what a method is.
