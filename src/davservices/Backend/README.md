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
| `ILockBackend` | Where the write locks of RFC 4918 §6 are kept, by path |
| `File\LockBackend` | Those locks in a directory, one file per lock |
| `Pdo\LockBackend` | Those locks in a table, for a server that is more than one machine |
| `IPrincipalBackend` | Where the people of RFC 3744 come from — a directory, a database, whatever the application already has |
| `File\PrincipalBackend` | Those people in a directory, one file per principal, written by a person |
| `Pdo\PrincipalBackend` | Those people in a table, for a deployment that already keeps its users in one |

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

and somewhere for the write locks, which is a store of its own:

```php
use DavServices\Backend\File\LockBackend as FileLocks;
use DavServices\Backend\Pdo\LockBackend as PdoLocks;

$locks = new FileLocks('/var/lib/davservices/locks');
```

or, where more than one machine answers requests:

```php
$locks = new PdoLocks(new PDO('mysql:host=db;dbname=dav', $user, $password));
```

Both are called `LockBackend`, one per kind of storage, so an application that
mentions both aliases them as above. Nothing in the library asks for `$locks`
yet — `LOCK` and `UNLOCK` are the next piece of work, and a server built today
still answers that it is not Class 2.

Every directory named here has to exist. Creating one on a guess is how a typo
ends up with a store in a web root.

**The table is not made for you.** `Pdo/locks.sql` beside the class is the
statement to run once. This library has no migration tool and no business
deciding when your schema changes; the tests of that class run that same file,
so it cannot quietly stop matching the queries. `ext-pdo` is a `suggest` rather
than a `require`, because making everybody install an extension for a backend
they may not use is not this library's decision to take.

The connection has to report its errors as exceptions, and is **asked** for
that rather than switched over: it belongs to the application, and every other
query made on it would change with the setting. PHP 8 hands out connections in
that mode already, so this is a wrong setting being named at construction
rather than a step anybody has to take.

**These are reference backends (R-BE-05) and are not to be run in production.**
They know nothing of two requests writing to one path at the same moment, of
quotas, or of what a filesystem does when it runs out of inodes. They are here
to be read, to be copied from, and to make the examples real.

## A lock storage has to outlive the process

There is deliberately **no** in-memory lock backend in this library. Two
requests to one server are two processes as often as not, and a lock only one
of them can see is not a lock — it would look like it worked and would hold
nothing. The file and database implementations are the real answer to
R-LOCK-05.

## The principal backends are read and never written

```php
$principals = new FilePrincipals('/var/lib/davservices/principals');
$principals = new PdoPrincipals(new PDO('mysql:host=db;dbname=dav', $user, $password));
```

`IPrincipalBackend` has no write methods, and that turns the design of the
file store around. The locks and the properties name their files after a hash
because nothing but the library ever looks at them; **here a person writes the
files**, so they keep whatever names they were given:

```xml
<principal xmlns="https://dav.services/principals" name="alice">
    <display-name>Alice Ashton</display-name>
    <alternate-uri>mailto:alice@example.test</alternate-uri>
</principal>
```

**The name inside the file is the one that counts** and the filename is not
read at all — which is also what keeps a principal from being called
`../../etc` and reaching out of the directory: a name that arrives as content
cannot be a path.

`Pdo/principals.sql` is the table, run once by whoever fills it. Its
`alternate_uris` column holds one URI per line rather than a table of its own,
which is a reference backend being a reference backend: nothing is written
through it, so a deployment that really keeps its people there can normalise
the column away without this library noticing.

**A store that cannot be read is not an empty store**, here as with the locks.
A principal that went missing is somebody who cannot sign in — or worse, whose
access control entries quietly stop matching anybody.

## What neither backend promises

Asking whether a path is free and then taking the lock are two steps, and
neither implementation makes them one: two requests can both find a path free
and both take an exclusive lock on it. This is a property of the interface, not
an oversight of the implementations — see `ILockBackend`, which says what a
deployment that cannot live with it has to do instead.

## What a damaged store means

The two kinds of storage answer that differently, and on purpose. The property
storage skips a file it cannot read and carries on; the lock storage refuses
the request. A property that goes missing is an inconvenience — a lock that
goes missing lets a write through that somebody was told could not happen.

## Writing another

Implement the interface and run the contract tests against it:
`tests/unit/Backend/PropertyStorageContract.php` and
`tests/unit/Backend/LockBackendContract.php` are each written once and
inherited by every implementation, because a backend that is exchangeable is
only exchangeable if they all behave the same.

Two rules in that contract are easy to get wrong. A value comes back **exactly**
as it went in, foreign namespaces and all — a storage that flattens XML to text
loses what it was trusted with, and the client is never told. And a path that
merely begins the same way is a different path: `alice2` does not lie below
`alice`, and a prefix comparison without the slash deletes somebody else's
properties.

The lock contract has a third: a lock that has run out is not a lock
(R-LOCK-03), and it is not handed back whatever is still in the store. Where a
backend can, it clears such a lock away while it is there anyway — nothing else
in this library goes looking for them.
