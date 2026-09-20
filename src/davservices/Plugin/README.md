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
| `Browser` | Shows what is in a collection, for development only — off unless it is switched on |

## Using it

```php
$properties = new DeadProperties(new PropertyStorage('/var/lib/davservices/properties'));
$properties->registerOn($server->events());
```

A plugin knows which seams it needs; an application should not have to. That is
what `registerOn()` is for — `DeadProperties` listens on three of them, and the
line above is the whole of what an application writes.

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
