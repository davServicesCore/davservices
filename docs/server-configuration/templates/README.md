# Deployment templates

These are reference configurations for a server that embeds davServices.
The library itself needs no configuration — but a misconfigured web server
breaks DAV in ways that are hard to diagnose from the client side.

| File | Purpose |
|---|---|
| `htaccess-app.conf.example` | Apache, application directory. Works independently of any parent `.htaccess`. |
| `htaccess-webroot.conf.example` | Apache, web root. Only needed when the app lives in a subdirectory. |
| `user.ini.example` | PHP settings. Under FastCGI, `php_value` in `.htaccess` causes a 500 — use this instead. |
| `nginx.conf.example` | nginx with PHP-FPM. |

## The three settings that break DAV silently

**`MultiViews`** — Apache's content negotiation rewrites the resolved resource
for files without an extension. WebDAV paths are exactly that. Symptom:
synchronisation fails for some resources and not others.

**The `Authorization` header** — under FastCGI, Apache does not pass it to the
CGI program. Symptom: nobody can log in, including the administrator, and the
server reports no error.

**An external redirect to the front controller** — DAV clients follow
redirects for `PROPFIND`, `PUT`, `REPORT` and `MOVE` unreliably or not at all;
for `MOVE` with a `Destination` header the behaviour is undefined. The rewrite
must be internal, without the `R` flag.

All three are addressed in the templates, with the reasoning inline.
