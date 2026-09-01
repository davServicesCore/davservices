# davServices

> A dependency-free PHP framework for WebDAV, CalDAV, and CardDAV servers.

[![CI](https://github.com/davServicesCore/davservices/actions/workflows/ci.yml/badge.svg)](https://github.com/davServicesCore/davservices/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/davservices/davservices)](https://packagist.org/packages/davservices/davservices)
[![PHP](https://img.shields.io/packagist/dependency-v/davservices/davservices/php)](https://packagist.org/packages/davservices/davservices)
[![License](https://img.shields.io/packagist/l/davservices/davservices)](LICENSE)

davServices turns a PHP application into a DAV server that standard calendar,
contact, and file clients can use without custom integration. You provide the
storage and authentication model; the library provides the HTTP and DAV
protocol layer.

**Contents:** [About](#about) | [Standards](#standards) | [Requirements](#requirements) | [Getting started](#getting-started) | [Configuration and deployment](#configuration-and-deployment) | [Documentation](#documentation) | [Project layout](#project-layout) | [Quality and CI](#quality-and-ci) | [Contributing](#contributing) | [Security](#security) | [License](#license)

## About

The framework is designed for applications that need interoperable DAV
endpoints for clients such as iOS, macOS, Thunderbird, DAVx5, and Evolution.
It intentionally has **no third-party runtime dependencies**: the production
package contains only `DavServices\` code and PHP's built-in capabilities.

That boundary is enforced by the repository's architecture checks. Development
tools such as PHPUnit, PHPStan, and PHP CS Fixer are used only while building
the library and are never part of a running server.

## Standards

| Area | Specification |
|---|---|
| WebDAV core | [RFC 4918](https://www.rfc-editor.org/rfc/rfc4918) |
| Access control | [RFC 3744](https://www.rfc-editor.org/rfc/rfc3744) |
| CalDAV | [RFC 4791](https://www.rfc-editor.org/rfc/rfc4791) |
| CalDAV scheduling | [RFC 6638](https://www.rfc-editor.org/rfc/rfc6638) |
| CardDAV | [RFC 6352](https://www.rfc-editor.org/rfc/rfc6352) |
| iCalendar | [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) |
| vCard 3.0 and 4.0 | [RFC 2426](https://www.rfc-editor.org/rfc/rfc2426), [RFC 6350](https://www.rfc-editor.org/rfc/rfc6350) |
| Collection sync | [RFC 6578](https://www.rfc-editor.org/rfc/rfc6578) |
| Service discovery | [RFC 6764](https://www.rfc-editor.org/rfc/rfc6764) |
| Quota | [RFC 4331](https://www.rfc-editor.org/rfc/rfc4331) |
| Extended MKCOL | [RFC 5689](https://www.rfc-editor.org/rfc/rfc5689) |

For the implementation status of each protocol section, see
[docs/rfc-compliance.md](https://github.com/davServicesCore/davservices/blob/main/docs/rfc-compliance.md).

## Requirements

- PHP 8.2 or newer
- PHP extensions: `dom`, `libxml`, `xml`, `xmlreader`, `xmlwriter`,
  `mbstring`, `json`, `filter`, and `hash`
- Composer for dependency-managed installation and development

The following extensions are optional:

- `intl` improves Unicode collation performance; results are the same without it.
- `openssl` is required only for the bundled TLS-capable transports.
- `pdo` is required only for bundled PDO reference backends.

## Getting started

### Install with Composer

```bash
composer require davservices/davservices
```

Composer is optional for deployment. The package includes its own PSR-4
autoloader:

```php
require '/path/to/davservices/autoload.php';
```

### Create a first server

```php
<?php

use DavServices\Backend\FilesystemBackend;
use DavServices\Dav\Server;

require __DIR__ . '/vendor/autoload.php';

$server = new Server(new FilesystemBackend('/srv/dav-storage'));
$server->setBaseUri('/dav/');
$server->run();
```

This creates a WebDAV endpoint. A client can use it for operations such as
`PROPFIND`, `PUT`, `LOCK`, and `MOVE`. To serve calendars or contacts, add the
respective plugins and a compatible backend. The guided setup is in
[docs/getting-started.md](https://github.com/davServicesCore/davservices/blob/main/docs/getting-started.md).

## Configuration and deployment

davServices itself has no global application configuration. The host
application chooses its URI, backend, authentication, and web-server setup.

Reference configurations are available in [server configuration templates](https://github.com/davServicesCore/davservices/tree/main/docs/server-configuration/templates):

| File | Use it for |
|---|---|
| [htaccess-app.conf.example](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration/templates/htaccess-app.conf.example) | Apache configuration in the application directory |
| [htaccess-webroot.conf.example](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration/templates/htaccess-webroot.conf.example) | Apache configuration when the app is in a subdirectory |
| [user.ini.example](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration/templates/user.ini.example) | PHP settings for FastCGI deployments |
| [nginx.conf.example](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration/templates/nginx.conf.example) | nginx with PHP-FPM |

When operating DAV endpoints, verify these points before debugging clients:

- Disable Apache `MultiViews`, which can resolve DAV resource paths incorrectly.
- Forward the `Authorization` header to PHP when using Apache with FastCGI.
- Rewrite requests internally to the front controller; external redirects are
  unreliable for DAV methods and `Destination` headers.

The rationale and template details are in [server configuration templates](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration/templates/README.md)
and [docs/server-configuration.md](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration.md).

## Documentation

| Document | What it covers |
|---|---|
| [Documentation index](https://github.com/davServicesCore/davservices/blob/main/docs/README.md) | Reading paths and documentation conventions |
| [Getting started](https://github.com/davServicesCore/davservices/blob/main/docs/getting-started.md) | First server and first calendar |
| [Architecture](https://github.com/davServicesCore/davservices/blob/main/docs/architecture.md) | Layers, tree model, and event system |
| [Writing a backend](https://github.com/davServicesCore/davservices/blob/main/docs/backends.md) | Backend contracts and batching |
| [Plugins](https://github.com/davServicesCore/davservices/blob/main/docs/plugins.md) | Extension points and guarantees |
| [Server configuration](https://github.com/davServicesCore/davservices/blob/main/docs/server-configuration.md) | Apache and nginx configuration |
| [RFC compliance](https://github.com/davServicesCore/davservices/blob/main/docs/rfc-compliance.md) | Implemented and unimplemented protocol behaviour |
| [Upgrading](https://github.com/davServicesCore/davservices/blob/main/docs/upgrading.md) | Version-to-version changes |

For the wider DAV ecosystem, the [WebDAV FAQ](http://www.webdav.org/other/faq.html),
[CalConnect Developer's Guide](https://devguide.calconnect.org/), and the
[IETF CALEXT working group](https://datatracker.ietf.org/wg/calext/) are useful
starting points.

## Project layout

```text
.
├── src/                 Framework source code (namespace: DavServices\)
├── tests/               Test suites and test configuration
│   ├── unit/            PHPUnit unit tests
│   └── integration/     Directly executable integration tests without PHPUnit
├── examples/            Minimal runnable examples and smoke tests
├── docs/                User, architecture, backend, and RFC documentation
├── docs/server-configuration/templates/
│                       Apache, nginx, and PHP deployment templates
├── bin/                 Repository architecture and coverage checks
├── assets/              Project assets
├── autoload.php         Composer-free PSR-4 autoloader
├── composer.json        Package metadata, requirements, and developer scripts
├── CONTRIBUTING.md      Contribution rules and quality expectations
├── SECURITY.md          Private security-reporting policy
└── LICENSE              Apache License 2.0
```

## Quality and CI

The [CI workflow](https://github.com/davServicesCore/davservices/blob/main/.github/workflows/ci.yml) runs on pushes to `main`, pull
requests, and manual dispatches.

| Check | What it validates |
|---|---|
| Architecture gates | Runtime dependency rule, namespaces, terminology, layers, and valid Composer metadata |
| Static analysis | PHPStan and the configured coding standard |
| PHP unit-test matrix | PHPUnit tests from `tests/unit/` on PHP 8.2, 8.3, and 8.4 |
| Coverage | 100% line and branch coverage on PHP 8.2 |
| Minimal extensions | The framework works without `intl` and `openssl` |
| Composer-free autoloading | A direct integration test loads every class through the bundled `autoload.php` |

For a development checkout, install the development tools and run every local
quality gate:

```bash
git clone https://github.com/davServicesCore/davservices.git
cd davservices
composer install
composer check
```

Useful focused commands:

```bash
composer test             # PHPUnit unit-test suite from tests/unit/
composer analyse          # PHPStan
composer style            # Check coding standard
composer test:coverage    # Create Clover and HTML coverage reports
composer mutation         # Run Infection mutation testing
```

## Contributing

DAV interoperability improves through real client reports. A pull request with
a failing test and a fix is ideal; a raw HTTP request and response is the next
best starting point for protocol bugs.

Before opening a pull request, work on a branch, add or update the relevant
test, and make sure `composer check` passes. Please open an issue before
starting larger changes, particularly where a behaviour may be constrained by
an RFC or an existing client expectation.

Read [CONTRIBUTING.md](https://github.com/davServicesCore/davservices/blob/main/CONTRIBUTING.md) for reporting guidance, coverage rules,
commit expectations, and the complete CI gates. Community conduct is governed
by [CODE_OF_CONDUCT.md](https://github.com/davServicesCore/davservices/blob/main/CODE_OF_CONDUCT.md).

## Security

Do not report security vulnerabilities in public issues. Follow the private
reporting process in [SECURITY.md](https://github.com/davServicesCore/davservices/blob/main/SECURITY.md).

## Project links

| Resource | Link |
|---|---|
| Homepage | [dav.services](https://dav.services) |
| Package | [packagist.org/packages/davservices/davservices](https://packagist.org/packages/davservices/davservices) |
| Source code | [github.com/davServicesCore/davservices](https://github.com/davServicesCore/davservices) |
| Issues | [GitHub issue tracker](https://github.com/davServicesCore/davservices/issues) |

## License

Licensed under the Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
