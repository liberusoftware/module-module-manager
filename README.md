# Liberu Module Manager

> Manifest discovery, dependency resolution, deployment enablement, registry caching, and diagnostics.

[Software](https://liberusoftware.com) · [Hosting](https://liberuhosting.com) · [Services](https://liberuservices.com) · [Liberu Group](https://liberugroup.com)

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/) [![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-module-manager?sort=semver)](https://github.com/liberusoftware/module-module-manager/releases/latest) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

## Feature scope

Manifest discovery, dependency resolution, deployment enablement, registry caching, and diagnostics. This repository owns this capability as an independently versioned Composer package; hosts integrate it through its declared public boundaries rather than application-specific classes.

### Capabilities

- `foundation.modules`

### Declared features

- Manifest discovery
- Dependency resolution
- Deployment enablement
- Registry caching
- Diagnostics

## Requirements and installation

| Dependency | Supported version |
|---|---|
| `php` | `^8.5` |
| `composer/semver` | `^3.4` |
| `illuminate/contracts` | `^13.0` |
| `illuminate/support` | `^13.0` |

Install the released package from the project root:

```bash
composer require liberusoftware/module-manager
```

The trusted `liberusoftware/composer-installer` places it in `/modules/module-manager`. The installed directory remains tracked by the host repository, while Composer and its lock file remain the source of version truth. Installation does not imply runtime enablement or commercial entitlement.

## Architecture and integration

- Composer package: `liberusoftware/module-manager`
- Package type: `liberu-module`
- Installer name: `module-manager`
- Category: `foundation`
- Service provider: `Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider`
- Enabled by default: `no`

### Public contracts

- No separate contract type is declared; use the documented provider and capability boundary.

### Failure isolation

By default, the first invalid module stops the boot. A host where one optional module must not take down the rest can opt in:

```php
// config/modules.php
'isolate_failures' => true,
'required' => ['module-manager', 'tenancy'],
```

A module that fails is then left out, logged and reported by `module:list`, and `module:validate` still fails, so a build stops before a deployment does. A module in `required` cannot be disabled, and if it fails, the boot stops with its name and the reason.

A provider that throws in `register()` is isolated. One that throws in `boot()` is not, because Laravel offers no hook for it.

Hosts should list modules that declare `extra.laravel.providers` under `extra.laravel.dont-discover`, or package discovery registers them whatever this configuration says. `module:validate --strict` fails if any remain.

### Commands

- `module:features {query?}` lists or searches feature declarations across installed modules.
- `src/Console/CacheModulesCommand.php`
- `src/Console/ClearModulesCommand.php`
- `src/Console/ListModulesCommand.php`
- `src/Console/ModuleStatusCommand.php`
- `src/Console/ValidateModulesCommand.php`: `--strict` also fails on modules registered by package discovery

### Events

- No package-owned event class is currently published.

### Persistence and permissions

- `module_activations` stores administrators' enable and disable choices.

### Feature modules

`ModuleManagement` lists and switches only modules whose manifest declares `"module_type": "feature"`. A manifest without the key, or with `"infrastructure"`, is infrastructure: it is never listed and cannot be switched there. `module:list` and `module:status` still show every module.

Authorization remains the host application's responsibility unless a public authorizer contract is listed above. Consumers should grant only the permissions needed for the exported capabilities and must not couple to internal tables or classes.

## Testing

The package includes 3 test file(s). From a compatible host checkout, run:

```bash
vendor/bin/pest modules/module-manager/tests
```

Changes must preserve the manifest, service-provider integration, package boundaries, and PHP/Laravel compatibility declared above.

## Security

Do not report security vulnerabilities through public GitHub issues. Email `security@liberusoftware.com` with reproduction details and the affected version so the report can be handled privately.

## License

This module is open-source software available under the [MIT License](LICENSE.md). The linked licence text is authoritative.

## Feedback and contributing

Focused issues and tested pull requests are welcome in the [GitHub repository](https://github.com/liberusoftware/module-module-manager). Keep changes within this module's capability boundary, update tests and documentation, and record user-visible changes in `CHANGELOG.md`.

## Contributors

Thank you to everyone who helps improve Liberu. [View the contributors graph](https://github.com/liberusoftware/module-module-manager/graphs/contributors).
