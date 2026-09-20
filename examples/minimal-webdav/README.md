# A minimal WebDAV server

Everything an application has to do, in one file: say where the data goes,
register the methods it means to answer, register the plugins it wants, and
hand the request over. [`public/index.php`](public/index.php) is about sixty
lines of that, and the rest of this page is about running it.

## Running it

```
php -S 127.0.0.1:8000 examples/minimal-webdav/public/index.php
```

The data goes under `examples/minimal-webdav/var/` — the files themselves in
`var/files`, the properties a filesystem has nowhere to put in
`var/properties`. Both are made on the first request and neither is in version
control.

Then point something at `http://127.0.0.1:8000/`:

```
curl -X MKCOL   http://127.0.0.1:8000/calendars
curl -T work.ics http://127.0.0.1:8000/calendars/work.ics
curl -X PROPFIND -H 'Depth: 1' http://127.0.0.1:8000/calendars
```

macOS mounts it with `Finder → Go → Connect to Server`, and Windows with
`net use * http://127.0.0.1:8000/`.

## What it is not

**It has no authentication and no access control.** Anyone who can reach the
port can read and write everything, which is what keeps it short enough to read
in one sitting — and what makes it a thing to run on your own machine rather
than on a network.

The directory browser is deliberately not registered. It is a development aid
(R-DAV-10) that publishes the shape of everything behind it; where it is wanted
while building something, two lines add it:

```php
$browser = Browser::forDevelopment($server);
$server->events()->on(BeforeMethod::class, $browser(...));
```

The filesystem backends are reference backends (R-BE-05) and are not built for
production either: they know nothing of two requests writing to one path at the
same moment, or of quotas.

## Litmus

[Litmus](https://github.com/tolsen/litmus) is the interoperability suite for
WebDAV servers, and this example is what the project runs it against. The
`basic`, `copymove` and `props` sets are run on every change in continuous
integration, against this very file (R-QS-04):

```
TESTS="basic copymove props" litmus http://127.0.0.1:8000/
```

`props` covers what a server does with properties, `copymove` the two methods
that are about two paths at once, and `basic` the rest of RFC 4918. The
remaining sets belong to the parts of the protocol that are still being built:
`locks` needs `LOCK` and `UNLOCK` (P3), and `http` covers conditional requests
against a running server.
