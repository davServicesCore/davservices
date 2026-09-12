# Http

The message layer. Everything a request carries on its way in and a response
carries on its way out, with no knowledge of WebDAV above it.

## Classes

| Class | Purpose |
|---|---|
| `Sapi` | The seam to PHP itself: builds a `Request` from `$_SERVER`, writes a `Response` back out |
| `Request` | One request as it arrived: method, target, path, query, headers, body |
| `Response` | One answer on its way back: status, reason phrase, headers, body |
| `Headers` | The header fields of one message: case-insensitive lookup, repeated fields kept, immutable |
| `Body` | The body of one message, readable as often as it is needed |
| `ByteRange` | The part of a resource a `Range` header asked for |
| `MalformedRequest` | Refusal of a request line that cannot be made sense of |
| `MalformedHeader` | Refusal of a field that may not go into a message |
| `MalformedResponse` | Refusal of a status that is not three digits — never the client's doing |

## Entry point

`Sapi` is where a real request begins and ends:

```php
$sapi = new Sapi();

$request = $sapi->request($_SERVER);
$sapi->send($server->handle($request));
```

Everything it touches of the outside world — the call that emits a header
field, the stream the body goes to, the output buffers it empties — is handed
in, so the whole of it is tested rather than only described. Left alone it uses
PHP's own `header()` and `php://output`.

`Request` is built once from what the SAPI hands over and never changes again:

```php
$request = new Request('PROPFIND', '/calendars/alice/?depth=1', $headers, $body);

$request->method();                        // 'PROPFIND'
$request->path();                          // 'calendars/alice'  — decoded, normalised
$request->query();                         // 'depth=1'          — raw, unparsed
$request->headers()->first('content-type');
$request->body()->contents(10_485_760);    // refused past the ceiling
```

`Headers` is immutable, so a field is added by taking a new collection:

```php
$headers = $headers->withAdded('DAV', '3');
```

`Body` copies what it is given into `php://temp` the first time somebody reads
it, so the chain of plugins that inspects a request each see the whole of it.
The copy is made on demand: most requests never read a body at all.

`Response` is built the same way — each change hands back a new answer, and
whoever runs the chain keeps the latest:

```php
$response = (new Response(207, body: $multiStatus))
    ->withHeader('Content-Type', 'application/xml; charset=utf-8')
    ->withHeader('DAV', '1', '3');
```

Its body is a string or a stream, and the difference is kept rather than
levelled out: a `PROPFIND` answer is built in memory, a `GET` of a file is not,
and reading a file into a string to send it would defeat the streaming of
R-TREE-02.

## Why immutable

The same collection is handed to every plugin in turn. One that a plugin could
alter behind the back of its holder makes a response impossible to reason
about, so `with()`, `withAdded()` and `without()` each return a new collection
and leave the one they were called on alone.

## What is refused

A field name that is not a token of RFC 9110 §5.1, and a value carrying any
control character other than a tab. A carriage return or a line feed in a value
ends the field and starts another one — that single character is the whole of
header injection, and it is refused rather than stripped: a value that had to be
tampered with is not a value worth sending.

## Ranges

`ByteRange::parse()` has three outcomes, and telling them apart is the whole of
it (RFC 9110 §14):

```php
$range = ByteRange::parse($request->headers()->first('Range'), $size);
```

| Outcome | Meaning | Answer |
|---|---|---|
| A `ByteRange` | The client asked for part of the resource | `206` with `Content-Range` |
| `null` | The header says nothing the server can follow | `200` with the whole resource |
| `RangeNotSatisfiable` | Well formed, but those bytes are not there | `416` |

The middle one is the one to get right. A unit the server does not know must be
ignored (§14.2), and a reversed range is *invalid* rather than unsatisfiable
(§14.1.1) — answering `416` to either would lock a client out of a file it is
allowed to read, over a header it need not have sent at all. Only one range is
served: R-HTTP-05 lets multipart go, and the whole resource is always a valid
answer.

## Ceilings

R-HTTP-12 gives XML bodies and uploads ceilings of their own, so the ceiling
belongs to the read rather than to the body: `contents($maximumBytes)` refuses
a body that is too long **while** it is being read. Storing an oversized body in
full and refusing it afterwards would spend exactly the memory and the disk the
ceiling exists to protect.

## Output buffers

Before a body goes out, `Sapi` empties the output buffers (R-HTTP-13): one that
is still open collects the body instead of letting it go, which turns streaming
a large file back into holding the whole of it in memory. They are flushed
rather than discarded — output a plugin produced by mistake is evidence of a
bug, and swallowing it would only make that bug harder to find.

## Boundaries

`$_SERVER`, `php://input` and `header()` are known to `Sapi` alone; nothing
above this directory has to hear of them. Conditional requests, ranges and the
WebDAV `If` header are parsed by their own classes here as they land.
