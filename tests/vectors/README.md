# Recurrence test vectors

`rfc5545-recurrence.json` holds **every example RFC 5545 §3.8.5.3 publishes**
of a recurrence rule, together with the answer the memo gives for it.

Thirty-nine examples, forty-two rules: three of the examples give two rules
for one answer, which makes each of those pairs a test of the specification
rather than of a rule.

It is meant to be useful outside this project. If you implement recurrence
expansion, you can run it against your own code without reading any of ours.

## What an entry says

```json
{
  "section": "3.8.5.3",
  "description": "Monthly on the second-to-last Monday of the month for 6 months",
  "zone": "America/New_York",
  "dtstart": "19970922T090000",
  "rule": "FREQ=MONTHLY;COUNT=6;BYDAY=-2MO",
  "exdate": [],
  "published": "(1997 9:00 AM EDT) September 22;October 20 (1997 9:00 AM EST) November 17;December 22 (1998 9:00 AM EST) January 19;February 16",
  "instances": [
    "19970922T090000", "19971020T090000", "19971117T090000",
    "19971222T090000", "19980119T090000", "19980216T090000"
  ]
}
```

| Field | What it is |
|---|---|
| `section` | where in RFC 5545 the example stands |
| `description` | the memo's own wording for the example |
| `zone` | the `TZID` the memo's `DTSTART` carries, for every example `America/New_York` |
| `dtstart` | the value of that `DTSTART`, without the parameter |
| `rule` | the `RRULE` value as the memo writes it, unfolded |
| `exdate` | the values of any `EXDATE` the example carries — one example does |
| `published` | **the memo's answer, verbatim** |
| `instances` | that answer as a list of `date-time` values |

**`published` is there so that the transcription can be audited.** The memo
writes its answers in prose — "September 2-11", "September 2,4,6,8...24,26,28,30"
— which no program can read. Expanding them into `instances` is a human step,
so the text it came from is kept beside the result: compare `published` with
the RFC, and `instances` with `published`.

## How to use it

Expand each entry as a component with that `DTSTART`, that `RRULE` and any
`EXDATE`, and take as many instances as the entry lists. They must match, in
order.

Three things are worth knowing before you compare:

1. **One example needs the whole recurrence set, not just the rule.** "Every
   Friday the 13th, forever" carries an `EXDATE` for its own `DTSTART`, which
   is §3.8.5.1's "The 'EXDATE' property can be used to exclude the value
   specified in 'DTSTART'". An implementation that expands only the `RRULE`
   will still pass it, but for the wrong reason.

2. **Where a rule repeats for ever, only the published prefix is checked.**
   The memo elides those answers with `...`, and the transcription stops
   exactly where the memo stops spelling the answer out.

3. **The times are wall-clock times.** Every example's start carries
   `TZID=America/New_York`, and the memo's answers are local times — it marks
   the summer-time changes only by writing `EDT` or `EST` beside them. So the
   instances are the local times the memo publishes, and turning one into an
   instant is a separate question that needs the zone.

## Where it comes from

RFC 5545 (September 2009), §3.8.5.3, which is in `docs/referenzen/rfc/`. The
set covers that section completely: a check reads every `DTSTART` and the
rules that follow it back out of the RFC text and requires each one to be
here.
