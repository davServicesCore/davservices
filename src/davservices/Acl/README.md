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
| `Privilege` | One privilege and what it aggregates — the tree of RFC 3744 §3, which can be grown |
| `PrivilegeSet` | What somebody holds on one resource, with the tree already walked |
| `IPrivilegeResolver` | The one seam through which an application's own rules reach the protocol |
| `MemoizingPrivilegeResolver` | The same, asked once per principal and path for the length of a request |

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

## Privileges are a tree, and the tree can grow

```php
$tree = Privilege::standard();                                   // RFC 3744 §3
$tree = $tree->with('{DAV:}read', new Privilege(FREE_BUSY));     // RFC 4791 §6.1.1
```

`DAV:write` is less a permission of its own than a name for four others, and
a server granting it without granting `DAV:bind` would let a client change a
file it may not create. Aggregation is what makes an access control list short
enough for a person to write and to read, and `PrivilegeSet` walks it once so
that nothing above has to.

The tree can grow because CalDAV sits in a layer **above** this one and brings
its own privilege. A tree that could not would force every extension's
privileges into the core.

## The resolver is where an application's rules come in

```php
$resolver = new MemoizingPrivilegeResolver($yourResolver);
```

Everything else in this library works out what a request *means*; the resolver
says whether the person making it is allowed to. Sharing, ownership,
delegation, whatever a deployment calls its rules — they all arrive here as a
set of privileges on a path.

**`forPaths()` is part of the contract, not a convenience.** A `PROPFIND` with
`Depth: 1` over two hundred members asks about two hundred paths; a resolver
answering them one at a time turns one request into two hundred queries while
satisfying every other rule. `tests/unit/Acl/PrivilegeResolverContract.php`
counts, so that such an implementation is caught rather than merely
disapproved of (R-PRIV-01).

The memoising wrapper is offered so that nobody writes that cache twice. It
remembers for **one request**: a cache that lived longer would go on granting
what an administrator had just taken away.

## Nothing here is written through WebDAV

A principal cannot be created, renamed or removed through the protocol. Who
exists is the application's business — a directory, an identity provider, a
table somebody else owns — and a `PROPPATCH` that appeared to rename somebody
while the directory kept the old name would be worse than a refusal.
