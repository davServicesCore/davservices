# Governance

## Who decides

davServices is maintained by a small group. Decisions are made by rough consensus among maintainers; where there is no consensus, the specification usually settles it, because most disagreements in this project are about what an RFC requires rather than about taste.

## What gets accepted

A change is accepted if it meets all of these:

1. It does not break the **no runtime dependencies** rule.
2. It does not move application knowledge into the protocol library.
3. It comes with tests, and `composer check` is green.
4. Where it changes protocol behaviour, it cites the specification section that requires the new behaviour.

Point 4 is the one that resolves most arguments. If the RFC says it, we do it. If the RFC is silent and a widely-used client expects it, we usually do it and mark it as a client accommodation rather than a protocol requirement. If neither applies, it needs a different case.

## What gets declined

- Anything that adds a third-party runtime dependency. This is not negotiable; it is the project's central promise.
- Anything that requires the library to own application-specific users, permissions, tenants or storage policy. Applications provide those concerns through the public backend and plugin interfaces.
- Convenience wrappers that hide protocol behaviour. This is a protocol library; callers are expected to know what a `PROPFIND` is.

Declined proposals get a written reason.

## Becoming a maintainer

There is no application. People who contribute consistently and show good judgement about what belongs in the library get asked. Consistency matters more than volume — someone who reviews carefully and reports precisely is more useful than someone who opens twenty pull requests in a week.

## Releases

Semantic versioning. A release happens when there is something worth releasing; there is no fixed schedule. Breaking changes to the public interface require a major version and a deprecation period of at least one minor version.

The list of extension points and the backend interfaces are part of the public interface. Classes marked `@internal` are not.

## If this project is abandoned

It is Apache-2.0. Fork it. The build gates and the contract test suite travel with the code, which is much of the point of putting them in the repository rather than in a document.
