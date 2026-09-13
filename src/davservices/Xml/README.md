# Xml

The XML a DAV request is made of, read and written. Requests are read with
`XMLReader` and never with `simplexml` (R-XML-01).

## Classes

| Class | Purpose |
|---|---|
| `Reader` | Reads a request body into elements, and refuses what it must |
| `Element` | One element: its name, its attributes, its children, its text |
| `Writer` | Writes an element tree out, escaped, with every namespace declared at the root |
| `MultiStatus` | Builds the `207` of RFC 4918 §13, whose shape everybody gets wrong |
| `MkColResponse` | Builds the `DAV:mkcol-response` of RFC 5689 §5.2: why a collection was not made |
| `ElementRegistry` | What each element means, by name — the seam plugins extend |

## Entry point

```php
$element = (new Reader())->parse($request->body()->contents(Reader::DEFAULT_MAXIMUM_BYTES));

$element->name();          // '{DAV:}propfind'
$element->children();      // the elements inside it, in order
$element->text();          // the text inside it, as it stands
$element->attribute('name');
```

## What an element means

```php
$registry->readWith('{DAV:}href', static fn (Element $element): string => trim($element->text()));

$registry->read($element);            // the value, or the element where nobody said
$registry->write('{DAV:}href', $href); // the element, or a refusal where nobody said
```

The two directions are deliberately not symmetric. **Reading tolerates the
unknown**: an element nobody registered a reader for comes back as it stands,
because a client asking for a property this server never heard of is an
ordinary Tuesday, and a dead property is by definition an element nobody knows
anything about. **Writing does not**: a value nobody registered a writer for is
refused, since something invented in its place would go out on the wire as
though it had been meant.

A later registration takes over from an earlier one — that is what makes the
registry an extension point rather than a table.

## Names carry their namespace

`{DAV:}propfind`, `{urn:ietf:params:xml:ns:caldav}calendar-query`, `{}plain`.
That is the form the whole library uses, because a prefix means nothing
(R-XML-03): `D:propfind`, `d:propfind` and `propfind` under a default namespace
are one element, and a client may pick whichever prefix it likes.

## Writing

```php
$multiStatus = new MultiStatus();
$multiStatus->addProperties('/calendars/alice/', [
    200 => ['{DAV:}displayname' => 'Alice'],
    404 => ['{DAV:}getctag' => null],
]);

$body = (new Writer())->write($multiStatus->toElement());
```

Two rules hold the writer together. **Everything is escaped** — a display name
a user chose, a path with an ampersand, a message from a backend all go out
inside XML that clients parse, and a value that could close its own element
would be a defect in every response at once. And **every namespace is declared
at the root**: declaring one where it is first used is legal, but a `PROPFIND`
answer would repeat its declarations on each of two hundred responses, and more
than one client in the wild reads only what the root declares.

A namespace nobody named a prefix for is given one derived from the namespace
itself. It is the same prefix wherever that namespace turns up, two namespaces
never share one, and it always begins with a letter — the hash behind it begins
with a digit often enough, and a prefix that did would be refused by every
parser. Its exact spelling is deliberately nobody's business.

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
