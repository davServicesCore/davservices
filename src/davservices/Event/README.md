# Event

Where plugins take part in what the server does. This is the lowest layer of
the library: it knows nothing of HTTP, XML or DAV, and everything above may
build on it.

## Classes

| Class | Purpose |
|---|---|
| `EventEmitter` | Registers listeners by event class and priority, and hands an event to them in turn |
| `IEvent` | What the emitter needs of an event: whether the chain is to go on |
| `Event` | The abstract base an event class normally extends; carries the stop flag |

## Entry point

```php
$emitter = new EventEmitter();

$emitter->on(BeforeMethod::class, static function (BeforeMethod $event): void {
    $event->stop();          // answered here; the server's own handler will not run
}, priority: 10);

$event = $emitter->emit(new BeforeMethod($request));
```

## The two rules

**The lower priority runs first**, and listeners that name the same priority run
in the order they were registered. Two plugins asking for the same place is the
ordinary case, and a server whose behaviour depended on how PHP happened to sort
them would be a server nobody could reason about.

**A stopped event ends the chain.** That is how a plugin *replaces* the default
handling of a method rather than merely running before it: it says on the event
that the matter is settled, and nothing registered behind it is called. An event
that arrives already stopped reaches no listener at all.

## Why events are objects

R-ARC-03 asks for it, and the reason shows up in every plugin: an object has
named, typed properties, a listener can be given the class it listens for, and
adding something to an event later is a change a static analyser can follow
through every plugin that listens. An array with a payload is none of those.

The listener's signature is checked statically rather than at run time. A class
that is no event is refused by PHPStan at the call site, and a second guard
inside the emitter could never fire for a caller who runs it.

## No static state

There is none anywhere in here (R-ARC-05), so one process may hold as many
emitters — and as many servers — as it likes (R-ARC-07). Two of them know
nothing of each other, which is what lets the test suite build hundreds.

## Boundaries

The events themselves live with whatever they are about: the ones listed in
R-ARC-04 — `beforeMethod`, `propFind`, `beforeBind` and the rest — belong to
the DAV layer, not here. This directory holds the machinery alone.
