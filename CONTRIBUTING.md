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

### Let git run them for you

```bash
composer hooks
```

One line, once per clone. It points `core.hooksPath` at the tracked `.githooks/` directory, and from then on git answers before GitHub does:

| hook | runs | measured |
|---|---|---|
| `pre-commit` | `composer gates:commit` — every gate that answers in seconds | about 7 s warm |
| `pre-push` | `composer gates:push` — those, plus the suite, the fuzz sweep and the Composer-free autoloader | about 75 s |

**The split is the point.** `git commit --no-verify` is one flag away, and a hook that costs minutes teaches people to reach for it; a check that gets switched off protects nothing. The requirements already draw that line for the slowest gate there is, R-QS-26: the mutation run "ist zu langsam für jeden Commit".

**And a hook has a second budget that is easy to miss.** Git opens the connection to the remote *before* running `pre-push`, because it has to tell the hook what would be pushed — so the hook's run time is time an idle connection is sitting there. The first version of this ran the whole of `composer check`, and five minutes of coverage shards later the server had given up: "Connection to github.com closed by remote host", and the push failed **after** every gate had passed. A hook that can make a good push fail is worse than a hook that checks less.

So the push stage holds what answers inside about a minute, and the coverage floor stays with `composer check` — where CI always runs it, and where you run it yourself while you work. **A coverage regression is therefore something CI still tells you about**, and that is the one thing these hooks do not catch.

The first `pre-commit` after a fresh clone is slower, because PHPStan has no cache yet. After that the cache is per file and only what you changed is re-analysed.

**Neither stage is a list beside `check`.** `tests/unit/Tooling/GatesTest.php` reads `composer.json` and holds both to it: every gate either stage runs is one `check` runs too, a push checks everything a commit checks and more, and the slow gates are absent on purpose rather than by accident. The one gate that appears under a different name — the suite without coverage against `check`'s suite with it — is named in that test rather than waved through.

**A green run here is not a green CI run, and it says so.** Three jobs are out of reach of any one machine, and the last lines of every gate run name them: the suite on PHP 8.3 and 8.4, the library on whichever side of `intl` your machine is not on (R-ENV-03 asks that it work without it, and CI proves both sides), and Litmus against `examples/minimal-webdav`. A hook that implied it had answered CI would be the most expensive kind of wrong.

To take the hooks off again: `git config --unset core.hooksPath`.

### What CI enforces

That runs the same gates as CI:

| Gate | What it rejects |
|---|---|
| `check:deps` | Any `use` of a class outside `DavServices\` and the PHP core |
| `check:namespace` | A namespace in `src/` that is not `DavServices\`, or a PSR-4 mismatch |
| `check:terms` | Application-layer vocabulary (`Space`, `Role`, `Session`, …) |
| `check:layers` | A dependency pointing from a lower layer to a higher one |
| `check:docblocks` | A shipped class or public method with no DocBlock, or one without prose |
| `check:metadata` | A `composer.json` that is not strictly valid |
| `style` | Deviations from PSR-12 plus the project rules |
| `analyse` | PHPStan level 9 findings |
| `check:fuzz` | A parser that hangs, runs out of memory, warns, or throws something it has not defined |
| `test` | Failing tests, and coverage below 100% |
| `check:autoload` | A class that cannot be loaded without Composer |

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

### The coverage run is split into shards

Branch coverage needs Xdebug, and Xdebug makes every call slower the more calls the process has made before. In one process, the cost of the coverage run grows with the square of the suite: it had reached nine minutes for tests that take seconds without coverage. So the run is cut into shards, each a process of its own, and the shards run side by side in CI.

You do not have to do anything for this. A new test file is picked up and placed in a shard on its own, and the number of shards grows with the suite (`bin/lib/TestShards.php`), so the run stays short as the suite grows. Three things keep it that way:

- Name test files `*Test.php` and keep them below `tests/unit/`. Only those are split into shards, and a test outside them is missing from the coverage run.
- Do not add a coverage run that bypasses the shards. `composer test:coverage` and every workflow go through `bin/coverage-shard.php` and `bin/merge-coverage.php`.
- If one shard becomes noticeably slower than the others, lower `TestShards::BYTES_PER_SHARD` rather than accepting a slow job.

## The fuzz run

`check:fuzz` takes the objects the RFCs publish, breaks them in named ways, and asks three things of every parser it feeds them to: no endless loop, no memory error, and no exception the library has not defined. A refusal is not a finding — the parsers refuse malformed input by design — but a `TypeError`, a warning, a run past its time limit or a process that dies is.

It is **deterministic**: a seed makes an input and the same seed makes it again. A finding therefore names the three things you need to see it for yourself, and the first thing to do with one is to make the input again:

```php
Mutations::apply('a flipped byte', Mutations::seeds()['a card'], 2417);
```

The gate spends a hundred seeds per combination, which fits inside a pull request; the nightly spends five thousand, starting where the gate stops. To go further yourself:

```bash
php tests/fuzz/fuzz.php --seeds=20000 --from=5101   # a long hunt
php tests/fuzz/fuzz.php --memory=64                 # under a tighter ceiling
```

Two things to know before changing it:

- **Run it with Xdebug off.** The run measures time, and under a coverage driver everything takes fifty times as long — every input would look like an endless loop. `composer check:fuzz` switches the driver off itself; a bare `php tests/fuzz/fuzz.php` does not.
- **A new target or mutation is added in one place.** `Targets::names()` and `Mutations::names()` are crossed with everything else, so a name added there is swept against every object, and the test lists under `tests/unit/Fuzz/` hold it to the same promises as the rest.

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
