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
| `IPropertyStorageBackend` | Where the dead properties of RFC 4918 §3 are kept, by path; carried along by a `MOVE`, duplicated by a `COPY` |
| `File\PropertyStorage` | Those properties in a directory, one file per path, as XML a person can read |
| `File\Directory` | A directory on a disc, served as a collection |
| `File\File` | One file on a disc: handed over as a stream, written as one |

## Using it

The shortest way to a working server:

```php
$server = new Server(new Tree(new Directory('/var/lib/davservices/files')));
```

and, beside it, somewhere to keep the properties a filesystem has nowhere to
put:

```php
$properties = new DeadProperties(new PropertyStorage('/var/lib/davservices/properties'));
$properties->registerOn($server->events());
```

Both directories have to exist. Creating one on a guess is how a typo ends up
with a store in a web root.

**These are reference backends (R-BE-05) and are not to be run in production.**
They know nothing of two requests writing to one path at the same moment, of
quotas, or of what a filesystem does when it runs out of inodes. They are here
to be read, to be copied from, and to make the examples real.

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
