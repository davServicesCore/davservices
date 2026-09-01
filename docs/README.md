# davServices documentation

| Document | Read it when |
|---|---|
| [Getting started](getting-started.md) | You want a running server in ten minutes |
| [Architecture](architecture.md) | You want to understand how the pieces fit |
| [Writing a backend](backends.md) | You are connecting your own storage |
| [Plugins](plugins.md) | You are extending or replacing behaviour |
| [Server configuration](server-configuration.md) | Clients fail to connect |
| [RFC compliance](rfc-compliance.md) | You need to know exactly what is implemented |
| [Upgrading](upgrading.md) | You are moving between major versions |
| [Anforderungen](requirements.md) | You need the normative requirement a behaviour derives from (German) |

## Conventions used here

Code samples assume Composer's autoloader. If you use the bundled one,
replace `vendor/autoload.php` with `path/to/davservices/autoload.php`.

Where behaviour is dictated by a specification, the relevant section is cited
inline — for example (RFC 4918 §9.1). Where davServices makes a choice the
specification leaves open, that is stated explicitly.

## A note on client behaviour

Several design decisions in this library exist because real clients deviate
from the specifications. Those are marked as such rather than presented as
protocol requirements, so that you can tell the two apart when debugging.
