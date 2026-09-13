# Xml

The XML a DAV request is made of, read and written. Requests are read with
`XMLReader` and never with `simplexml` (R-XML-01).

## Classes

| Class | Purpose |
|---|---|
| `Reader` | Reads a request body into elements, and refuses what it must |
| `Element` | One element: its name, its attributes, its children, its text |
| `Writer` | Placeholder; the real writer arrives with the multi-status |

## Entry point

```php
$element = (new Reader())->parse($request->body()->contents(Reader::DEFAULT_MAXIMUM_BYTES));

$element->name();          // '{DAV:}propfind'
$element->children();      // the elements inside it, in order
$element->text();          // the text inside it, as it stands
$element->attribute('name');
```

## Names carry their namespace

`{DAV:}propfind`, `{urn:ietf:params:xml:ns:caldav}calendar-query`, `{}plain`.
That is the form the whole library uses, because a prefix means nothing
(R-XML-03): `D:propfind`, `d:propfind` and `propfind` under a default namespace
are one element, and a client may pick whichever prefix it likes.

## What is refused

This is the most exposed class in the library — it is handed whatever an
unauthenticated stranger cared to send.

| Refused | Why |
|---|---|
| Any document type declaration | It is how `file:///etc/passwd` ends up in a response, and how ten nested entities turn one line into three gigabytes (R-XML-04) |
| A document past `maximumBytes` | Before it is parsed at all |
| Nesting past `maximumDepth` | |
| More elements than `maximumElements` | |
| Anything not well formed | With the line number, which is the one thing this library knows that a client developer does not |

Every refusal is a `400` — never a memory error, never a server still chewing
(R-XML-05). The limits are constructor arguments; the defaults are generous for
DAV, where the largest legitimate request is a multiget of five thousand hrefs.

## Text is kept as it stands

Whitespace included, between elements as well as inside them. A `text-match` of
CalDAV searches for exactly what the client wrote, so trimming here would
quietly change what a search finds. Callers that want a token — an href, a name
— trim it themselves.

## libxml is left as it was found

Its error handling is shared with everything else in the process. The reader
switches error collection on, puts it back afterwards, and clears both before
and after: an error somebody else left behind must not be read as a fault in
this document, and its own must not be read as a fault in the next.

## Two lines that cannot be reached

`Reader` carries two guards marked `@codeCoverageIgnore` (R-CODE-10): PHP
declares return values for `XMLReader::XML()` and for a document without a root
element that libxml does not produce — an empty document is refused before, and
a document without a root is not well formed. They are caught all the same,
because a fatal error would be a worse answer than a `400`.
