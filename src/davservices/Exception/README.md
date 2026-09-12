# Exception

Every refusal the library reports to a client. The directory belongs to no
layer: the HTTP layer and the backends alike throw from here, and the server
turns what it catches into a response.

## Classes

| Class | Purpose |
|---|---|
| `IHttpFailure` | What the server needs to answer a failure: the status, and the precondition to name in a `DAV:error` body |
| `DavException` | The abstract base; fixes the status of a subclass in a constant and hands it on as the exception code |
| `BadRequest` … `InsufficientStorage` | One class per status the library reports |

## The statuses

| Status | Class | Thrown when |
|---|---|---|
| 400 | `BadRequest` | The request cannot be parsed |
| 401 | `Unauthorized` | Credentials are missing or wrong |
| 403 | `Forbidden` | Understood and refused |
| 404 | `NotFound` | Nothing bound there, or the caller may not know |
| 405 | `MethodNotAllowed` | The method does not apply to this resource |
| 409 | `Conflict` | The parent collection is missing |
| 412 | `PreconditionFailed` | An `If`, `If-Match` or `If-None-Match` did not hold |
| 413 | `PayloadTooLarge` | The body exceeds the configured limit |
| 415 | `UnsupportedMediaType` | The resource cannot hold that media type |
| 416 | `RangeNotSatisfiable` | The `Range` lies outside the resource |
| 423 | `Locked` | A write lock stands in the way |
| 501 | `NotImplemented` | No plugin provides the method |
| 502 | `BadGateway` | An upstream server did not play along |
| 507 | `InsufficientStorage` | A quota is exhausted, or a report is truncated |

## Entry point

Throw the class that names the status. Pass the precondition of RFC 4918 §16 as
the second argument where one applies:

```php
throw new Forbidden('Depth: infinity is disabled.', '{DAV:}propfind-finite-depth');
```

## Adding one

A new status is one file: a class DocBlock saying when it is thrown, and
`protected const STATUS`. Nothing else, by design — a hierarchy that costs a
method per member grows a status nobody dares to add.

## Boundaries

The `Allow` header of a `405`, the `WWW-Authenticate` of a `401` and the
`Content-Range` of a `416` are set by the server and the plugins, which know
the resource. An exception carries the status and its precondition, no more.
