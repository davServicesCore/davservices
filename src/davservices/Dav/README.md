# Dav

The tree a DAV server serves: what a node is, and how a path becomes one.

Everything a backend implements starts here. The interfaces say what the server
may ask of a node; the `Tree` is the only thing that turns a path into one.

## Classes

| Class | Purpose |
|---|---|
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
