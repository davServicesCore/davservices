# Http

The message layer. Everything a request carries on its way in and a response
carries on its way out, with no knowledge of WebDAV above it.

## Classes

| Class | Purpose |
|---|---|
| `Headers` | The header fields of one message: case-insensitive lookup, repeated fields kept, immutable |
| `MalformedHeader` | Refusal of a field that may not go into a message |
| `Request` | Placeholder; the real value object arrives with the SAPI adapter |

## Entry point

`Headers` is built once from what the SAPI hands over and then only ever
replaced, never altered:

```php
$headers = new Headers(['Content-Type' => 'text/xml', 'Depth' => '1']);

$headers->first('content-type');          // 'text/xml'   — case does not matter
$headers->all('DAV');                     // []           — absent is empty, not null
$headers = $headers->withAdded('DAV', '3');
```

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

## Boundaries

Reading `$_SERVER`, streaming a body and writing the response belong to the SAPI
adapter. Conditional requests, ranges and the WebDAV `If` header are parsed by
their own classes in this directory as they land.
