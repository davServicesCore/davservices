# Acl

Access control, after RFC 3744. This layer sits above the backends and below
the plugins: it may use the tree, the events and a backend, and nothing below
it may use it.

**The idea of RFC 3744 is that the people a server knows are resources.**
Rather than a private notion of users, each of them has a URL, a `PROPFIND`
answers what they are called, and an access control entry points at one of
those URLs. Everything else in this layer is built on that.

## Classes

| Class | Purpose |
|---|---|
| `Principal` | One principal as a resource: what it is called, how else to reach it |
| `PrincipalCollection` | Where the principals hang, mounted wherever an application likes |

The model they are made from is `Dav\Acl\PrincipalInfo`, one layer down,
because a backend interface may not reach up into the layer that uses it —
the same reason `Dav\Locks\LockInfo` lives where it does.

## Using it

```php
$root->add(new PrincipalCollection('principals', $yourPrincipalBackend));
```

The path is the application's choice. Nothing here assumes one:
`DAV:principal-collection-set` is what tells a client where to look, and
`Plugin\Principals` answers it with whatever path the collection was mounted
at.

## Nothing here is written through WebDAV

A principal cannot be created, renamed or removed through the protocol. Who
exists is the application's business — a directory, an identity provider, a
table somebody else owns — and a `PROPPATCH` that appeared to rename somebody
while the directory kept the old name would be worse than a refusal.
