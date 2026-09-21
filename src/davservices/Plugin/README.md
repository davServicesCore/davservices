# Plugin

What the server can do beyond plain WebDAV, and nothing it has to. Every plugin
here is listeners on the emitter (R-ARC-02): the methods know nothing about
them, and an application that wants none of them registers none.

This is the highest layer. A plugin may use anything below it — the tree, the
events, a backend — and nothing below may use a plugin.

## Classes

| Class | Purpose |
|---|---|
| `DeadProperties` | Keeps the properties the server does not understand, in a storage backend |
| `Locks` | `LOCK` and `UNLOCK`, and what a client is told about a hold |
| `Browser` | Shows what is in a collection, for development only — off unless it is switched on |

## Using it

```php
$properties = new DeadProperties(new PropertyStorage('/var/lib/davservices/properties'));
$properties->registerOn($server->events());
```

A plugin knows which seams it needs; an application should not have to. That is
what `registerOn()` is for — `DeadProperties` listens on three of them, and the
line above is the whole of what an application writes.

## Locking is a plugin, and that is a promise

```php
(new Locks($server, new LockBackend('/var/lib/davservices/locks')))->register();
```

That line is the difference between `DAV: 1` and `DAV: 1, 2`. Without it a
`LOCK` is a `501` and the server is a plain WebDAV server rather than a broken
one — which is what R-LOCK-06 asks for, and why the first test of the plugin is
the one that proves it.

`register()` takes no argument because the plugin was handed the server when it
was made: it brings two **methods** as well as listeners, and a method has to be
registered with the server rather than with the emitter.

The third argument is the longest a lock is handed out for, whatever a client
asks (R-LOCK-03). An hour is the default. `Timeout: Infinite` is answered with
that maximum rather than with forever: a lock that never ends is one nobody can
clear after a client has crashed.

**What this plugin does not yet do is refuse the writes a lock stands in the
way of.** That needs the `If` header evaluated against the locks held, and it
is the next piece of work. Until then a lock is a promise this server keeps a
record of and tells the truth about, in `OPTIONS`, in `DAV:lockdiscovery` and
in the answer to a second `LOCK` — but a `PUT` from somebody who never asked
still goes through.

## The browser is not a web interface

```php
$browser = Browser::forDevelopment($server);
$server->events()->on(BeforeMethod::class, $browser(...));
```

R-DAV-10 asks for a directory browser as a **development aid**, and asks as
plainly that it never be switched on in production: a server that did would be
publishing the shape of every account to anyone who could reach the path. The
factory is named the way it is so that the warning stands in the line somebody
writes, not only in a docblock they may never open — and the page itself says
what it is, for whoever ends up looking at one.

Until those two lines are written, a `GET` on a collection is the `405` plain
WebDAV gives.

## Writing another

A plugin is an ordinary class. It needs no base class and no registration
anywhere but the emitter, and it can be tested by handing it an event rather
than by standing up a server.
