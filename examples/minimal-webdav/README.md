# Minimal DAV server

The smallest useful davServices setup: a filesystem-backed DAV server with
WebDAV, CalDAV, and CardDAV endpoints and no access control.

The server exposes these ready-to-use scenarios:

| Protocol | Collection | Typical client URL |
|---|---|---|
| WebDAV | Files and folders | `http://127.0.0.1:8080/files/` |
| CalDAV | Calendars and iCalendar objects | `http://127.0.0.1:8080/calendars/` |
| CardDAV | Address books and vCard objects | `http://127.0.0.1:8080/addressbooks/` |

### CalDAV example

Register the CalDAV plugin with a calendar-capable backend, then configure a
calendar client to use the calendar collection URL:

```text
http://127.0.0.1:8080/calendars/
```

The client uses `MKCALENDAR`, `PROPFIND`, `REPORT`, `PUT`, and `DELETE` to
create calendars, discover their properties, query events, and synchronise
iCalendar objects.

### CardDAV example

Register the CardDAV plugin with an address-book-capable backend, then
configure a contacts client to use the address-book collection URL:

```text
http://127.0.0.1:8080/addressbooks/
```

The client uses extended `MKCOL`, `PROPFIND`, `REPORT`, `PUT`, and `DELETE` to
create address books, discover their properties, search contacts, and
synchronise vCard objects.

```bash
php -S 127.0.0.1:8080 -t .
```

Then configure a WebDAV, CalDAV, or CardDAV client with the relevant endpoint
from the table above.

