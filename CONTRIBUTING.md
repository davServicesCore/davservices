# Contributing

davServices is open source because protocol implementations get better when the people who hit the edge cases can fix them. Interoperability bugs in particular are found by users, not by us — a report that says "iOS 18.4 drops the alarm on recurring events" is worth more than any amount of internal testing.

**Everything is welcome.** A bug report. A question that turns out to be a documentation gap. A wish for a feature. A one-line typo fix. And best of all: a bug report that arrives with the fix attached.

## How to contribute, in order of usefulness

| | What | Why it helps |
|---|---|---|
| 1 | **A pull request with a failing test and the fix** | The ideal. The test proves the bug, the fix proves the understanding, and both stay in the suite so it never comes back. |
| 2 | **A pull request with a failing test only** | Almost as good. If you can reproduce it but not fix it, the reproduction is the hard part anyway. Mark it `@group known-failure` and open the PR. |
| 3 | **A bug report with the raw HTTP exchange** | Request and response, headers included. Ninety percent of protocol bugs are obvious from the wire. |
| 4 | **An interoperability report** | Which client, which version, what happened. Even without a diagnosis. Knowing that eM Client 10 behaves differently from eM Client 9 is useful on its own. |
| 5 | **A feature wish as an issue** | Open one. If it is out of scope we will say so and why — that answer is itself documentation. |
| 6 | **A question** | Open an issue. If you had to ask, the documentation was unclear, and that is a bug in the documentation. |

There is no contribution too small. Fixing a typo in a doc block is a real contribution, and it is how most long-term contributors start.

## Reporting a bug

Please use the issue templates — they ask for exactly what we need and nothing more. The single most useful thing you can include is the **raw HTTP exchange**:

```http
PROPFIND /dav/users/felix/calendars/privat/ HTTP/1.1
Depth: 1
Content-Type: text/xml; charset=utf-8

<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>
```

If a specification governs the behaviour, cite the section. If you are not sure which section, say so — finding it is part of the work and we are happy to do it.

**Security issues do not belong in the issue tracker.** See
[SECURITY.md](SECURITY.md).

## Suggesting a feature

Open an issue before writing code. Not because we want to gatekeep, but because protocol behaviour is constrained in ways that are rarely obvious from the outside, and a short conversation can save you a rewrite.

Two things help a feature proposal along:

- **Which specification allows it.** If an RFC defines it, say which. If nothing does, say that too — proprietary extensions are not automatically out, but they need a different justification.
- **Which client needs it.** "DAVx⁵ expects this property" is a much stronger argument than "it would be nice".

Proposals that are out of scope get a written reason, not a silent close.

## Your first pull request

```bash
git clone https://github.com/davServicesCore/davservices
cd davservices
composer install
composer check      # must be green before you change anything
```

Maintainers must develop all changes in a dedicated branch and must not commit directly to `main`. External contributors should use the usual fork-and-pull-request workflow. In either case, write a failing test, make it pass, and run `composer check` before opening the pull request. Every pull request must pass `composer check` and receive maintainer review before it is merged.

If `composer check` was green before and is green after, you are done.

Do not worry about getting the branch name, commit format or changelog entry perfect. If something is missing we will say so, and it takes a minute to fix.

## Before you start

For anything larger than a bug fix, please open an issue first. Protocol behaviour is often constrained in ways that are not obvious from the code, and a short conversation can save you a rewrite.

## What the pipeline enforces

Run everything locally before pushing:

```bash
composer check
```

That runs the same gates as CI:

| Gate | What it rejects |
|---|---|
| `check:deps` | Any `use` of a class outside `DavServices\` and the PHP core |
| `check:namespace` | A namespace in `src/` that is not `DavServices\`, or a PSR-4 mismatch |
| `check:terms` | Application-layer vocabulary (`Space`, `Role`, `Session`, …) |
| `check:layers` | A dependency pointing from a lower layer to a higher one |
| `style` | Deviations from PSR-12 plus the project rules |
| `analyse` | PHPStan level 9 findings |
| `test` | Failing tests, and coverage below 100% |

None of these are advisory. A pull request that fails any of them cannot be merged.

## The no-dependency rule

This is the project's central promise and is not negotiable. If you find yourself wanting a third-party package in `require`, that is a signal to either implement the small part you need or to reconsider the approach.

Development dependencies are fine — they never reach a production code path.

## Test coverage

The target is 100% line and branch coverage. If a line genuinely cannot be reached, mark it and say why:

```php
// @codeCoverageIgnoreStart
// Unreachable: libxml always sets an error before returning false here.
throw new \LogicException('libxml reported failure without an error');
// @codeCoverageIgnoreEnd
```

Coverage alone is not the goal. The nightly mutation run changes your code and expects a test to fail; a test that executes a line without asserting anything will show up there.

## Recognition

Contributors are listed in the release notes of the version their change ships in. If you would rather not be named, say so in the pull request.

## Commit messages

One logical change per commit. Present tense, imperative mood, and a body that explains *why* when the *what* is not self-evident.

```
Reject If-header lists with unbalanced parentheses

RFC 4918 §10.4.2 leaves the behaviour undefined, but Litmus expects a 400 rather than a silent match. Thunderbird sends these when a lock times out mid-request.
```

## Where to look things up

The `referenzen/` directory of the requirements package maps concrete questions to the exact specification section that answers them, split by protocol — WebDAV for files, CalDAV for calendars, CardDAV for address books. It also contains a script that fetches every relevant RFC.

If you are new to DAV, start with the
[WebDAV FAQ](http://www.webdav.org/other/faq.html), then the [CalConnect Developer's Guide](https://devguide.calconnect.org/) — the latter is a cookbook rather than a specification and covers the parts where the RFCs are silent and reality is not.

## A note on where this project lives

davServices implements protocols maintained by the IETF. The working group for calendaring and contacts is [CALEXT](https://datatracker.ietf.org/wg/calext/); its [mailing list](https://www.ietf.org/mailman/listinfo/calsify) is open to anyone and subscribing to it *is* participation — there is no membership and no fee. Contributors are encouraged to follow it. Several of the questions you might open an issue about have already been answered there.
