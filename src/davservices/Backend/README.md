# Backend

Where a server's own data is kept. A backend is what an application swaps out
to move from files to a database without the protocol noticing.

The interfaces live here and the implementations beside them, one directory per
kind of storage. They sit below `Plugin`, because a plugin is written against a
backend's interface — the dead properties against a property storage, later the
locks against a lock storage — and no backend has any business knowing which
plugin is using it.

## Classes

| Class | Purpose |
|---|---|
| `IPropertyStorageBackend` | Where the dead properties of RFC 4918 §3 are kept, by path |
| `File\PropertyStorage` | Those properties in a directory, one file per path, as XML a person can read |

## Using it

```php
$storage = new PropertyStorage('/var/lib/davservices/properties');
```

The directory has to exist. Creating one on a guess is how a typo ends up with
a properties store in a web root.

## Writing another

Implement `IPropertyStorageBackend` and run the contract tests against it:
`tests/unit/Backend/PropertyStorageContract.php` is written once and inherited
by every implementation, because a backend that is exchangeable is only
exchangeable if they all behave the same.

Two rules in that contract are easy to get wrong. A value comes back **exactly**
as it went in, foreign namespaces and all — a storage that flattens XML to text
loses what it was trusted with, and the client is never told. And a path that
merely begins the same way is a different path: `alice2` does not lie below
`alice`, and a prefix comparison without the slash deletes somebody else's
properties.
