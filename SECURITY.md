# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |
| < 1.0 | No |

## Reporting a vulnerability

Please report security issues privately through [GitHub Security Advisories](https://github.com/davServicesCore/davservices/security/advisories/new), or by email to **security@dav.services**.

Please do **not** open a public issue for a security problem.

### What to include

- The affected version and the conditions under which the issue occurs
- A description of the impact
- A reproduction, ideally as a failing test or a raw HTTP exchange

### What to expect

| | |
|---|---|
| Acknowledgement | within 3 working days |
| Initial assessment | within 10 working days |
| Fix or mitigation plan | agreed with you before disclosure |

You will be credited in the advisory unless you prefer otherwise.

## Areas of particular interest

The following carry elevated risk in a DAV server and are worth extra scrutiny:

- **XML processing** — external entities, entity expansion, depth and size limits
- **Path handling** — traversal, symlink escapes, encoding tricks, NUL bytes
- **The `If` header parser** — RFC 4918 §10.4 has a complex grammar
- **Access control** — any path where a privilege check can be reached only conditionally, particularly inside `207 Multi-Status` responses
- **Recurrence expansion** — unbounded iteration from a crafted `RRULE`
- **Resource limits** — anywhere a small request produces a large amount of work

## What is out of scope

- Vulnerabilities in code that embeds this library rather than in the library
- Missing hardening in the bundled reference backends, which are documented as demonstration code and not for production use
- Issues that require a server configuration the documentation explicitly warns against
