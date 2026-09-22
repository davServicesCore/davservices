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
| `SearchableProperty` | One property principals may be searched by, with the sentence that explains it |
| `Report\PrincipalSearchPropertySet` | The report that says which of those there are (RFC 3744 §9.5) |
| `Report\PrincipalPropertySearch` | The report that finds a person by what their properties hold (RFC 3744 §9.4) |

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

## What a client may search by

```php
$report->on(
    '{DAV:}principal-search-property-set',
    new Report\PrincipalSearchPropertySet($server, SearchableProperty::standard())(...),
);
```

**A client cannot guess what a server will search.** RFC 3744 §9.4 leaves the
search method — exact, prefix, substring, cased or not — to the server, and a
search over a property this server does not search does not fail: it matches
nobody. So §9.5 has a report to be asked first, and this is it.

Like a privilege, each searchable property carries the sentence that explains
it and the language that sentence is in, because the DTD of §9.5 requires
both. The list is a **list**: §9.5 asks that the most frequently searched come
first, so that a client with little room on screen shows the ones people use.

The standard list is what this library can actually answer for a principal —
`DAV:displayname` and `DAV:alternate-URI-set`. An application that answers
more adds to it rather than replacing it, the same way the privilege tree
grows.

## Finding a person

```php
$report->on(
    '{DAV:}principal-property-search',
    (new Report\PrincipalPropertySearch($server, $searchable))(...),
);
```

This is what happens when somebody types three letters into the invitation
field of a calendar client. **What counts as a match is the server's to
choose** (§9.4) — the people usually live in somebody else's directory, and an
LDAP attribute already has its own answer. Where nothing constrains it, §9.4
names the preferred default, and that is what this does: caseless substring,
over each contiguous piece of text in the value (§9.4.1). The logic is not
open: several searches, and several properties in one of them, are all AND.
RFC 3744 has no `test` attribute.

**The values are assembled the way a `PROPFIND` assembles them**, which is
why `IPrincipalBackend` has no `search()`. Not every searchable property
belongs to the backend: the one CalDAV clients really search by comes from a
plugin, and dead properties come from the property storage. A backend search
could only answer for its own, would need this path for the rest anyway, and
would leave two places deciding what „matches“ means. It also settles what a
client may not see — a concealed member is not searched, and a property
answered with `403` does not match, because an href in the answer says the
resource exists whether or not any properties come with it.

The limit is not optional. A directory of a hundred thousand people answers a
search for „a“ with all of them, so too many matches is refused with
`DAV:number-of-matches-within-limits` — the postcondition §9.4 names, so
clients meet a name they already understand.

## Privileges are a tree, and the tree can grow

```php
$tree = Privilege::standard();                                   // RFC 3744 §3
$tree = $tree->with('{DAV:}read', new Privilege(FREE_BUSY));     // RFC 4791 §6.1.1
```

Each privilege carries the sentence that explains it and the language that
sentence is in, because RFC 3744 §5.3 requires both in
`DAV:supported-privilege-set` — an extension that brings a privilege brings
its description with it, or it brings a hole in a required element. It also
says whether it may be put in an entry at all (`DAV:abstract`); nothing in the
standard tree is abstract, because `DAV:write` is a privilege §3.2 defines in
its own right and an administrator who means to grant it should be able to
write it down.

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
