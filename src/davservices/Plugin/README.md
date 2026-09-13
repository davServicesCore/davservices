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

## Using it

```php
$properties = new DeadProperties(new PropertyStorage('/var/lib/davservices/properties'));
$properties->registerOn($server->events());
```

A plugin knows which seams it needs; an application should not have to. That is
what `registerOn()` is for — `DeadProperties` listens on three of them, and the
line above is the whole of what an application writes.

## Writing another

A plugin is an ordinary class. It needs no base class and no registration
anywhere but the emitter, and it can be tested by handing it an event rather
than by standing up a server.
