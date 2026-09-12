# Uri

Conversion between request targets and the internal path form. This is the
lowest layer of the library: it knows nothing about HTTP, XML or DAV, and
everything above it depends on the guarantees given here.

## The internal path form

Decoded, no leading or trailing slash, the root spelled as the empty string:

```
''                                  the root
'calendars'                         a collection
'calendars/alice/work week.ics'     a resource
```

## Classes

| Class | Purpose |
|---|---|
| `Path` | `normalise()` in, `encode()` out, plus `segments()`, `split()` and `join()` for working on an internal path |
| `MalformedPath` | Refusal of a target that cannot be resolved safely; the HTTP layer answers it with `400` |

## Entry point

`Path::normalise()` for anything arriving from a client, `Path::encode()` for
anything leaving in a `DAV:href`.

## Where the safety comes from

A target is cut into segments **before** it is decoded, and it is decoded
**exactly once** (RFC 3986 §3.3 and §2.3). A `%2F` can therefore never become a
separator and a `%2e%2e` never a dot segment. Both decode into an ordinary name,
which is then refused — as are separators, control characters and invalid UTF-8
inside a name, and any target that climbs above the root.

## Boundaries

The base URI a server is mounted under, the `Destination` header of `COPY` and
`MOVE`, and anything else that carries a full URL are handled by the HTTP layer.
This directory deals with paths alone.
