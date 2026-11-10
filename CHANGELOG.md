# Changelog

## 1.9.0 - Unreleased

- `ModuleManagement::modules()` includes each module's manifest description, and `Manifest` gains `description()`.
- Add the optional manifest key `module_type`, `feature` or `infrastructure`, defaulting to `infrastructure`. `ModuleManagement` lists and switches only feature modules, so infrastructure such as this package never appears as something to turn off.

## 1.8.0 - 2026-11-10

- Add `ModuleManagement`: lists Composer-installed modules with their installed version and status, and enables or disables them. A choice is stored in the new `module_activations` table and applied at the next boot; Composer still installs and removes packages. Required modules, modules in `MODULES_DISABLED` and modules Laravel auto-discovers cannot be changed, and a change that would leave a module without a dependency is refused.

## 1.7.0 - 2026-11-06

- Add opt-in failure isolation. With `modules.isolate_failures`, a module that fails discovery, validation, dependency resolution or provider registration is left out and reported, and the rest of the application boots. Off by default, so existing hosts still fail fast.
- Add `modules.required`, for modules a host cannot run without. With isolation on, these cannot be disabled, and a failure among them still stops the boot with the module's name.
- Add `ModuleLoader`, `ModuleInventory` and `ModuleDiscovery::discoverTolerant()`.
- `module:list` gains `--json` and Kind, Status and Problems columns. `module:status` gains status, kind and problems. `module:validate` reports invalid manifests one by one, warns when Laravel package discovery registers a module, and fails on that with `--strict`.
- With isolation on, `module:cache` validates first, because a cached boot then skips validation.
- Fix discovery rejecting identical copies of a module under two roots.

## 1.2.1 - 2026-08-06

- Split CI into the three workflows `CONFORMANCE.md` §3.9 asks for, rather than one calling all
  three. Only `tests` runs on every push; installing from nothing and resolving the lowest allowed
  dependencies are release questions and now run on tags. One caller meant four jobs per push, and
  a 44-repository publish sweep stalled the organisation's Actions queue.

## 1.2.0 - 2026-08-06

- Move the boundary suites to `liberusoftware/package-testbench`: this repository no longer
  ships a test bootstrap or its own metadata and provider tests, and its `phpunit.xml` points
  at the shipped suite instead. A new boundary rule is now a testbench release rather than a
  change made by hand in every package repository.
- Call the reusable workflows in `liberusoftware/.github` rather than restating CI setup.

## 1.1.0 - 2026-08-05

- Conformance wave: package and namespace renames, manifest categories and `default_enabled`
  settled against `liberusoftware/documentation`. See the host's `docs/CONFORMANCE.md` §3.

## 1.0.6 - 2026-08-01

- Correct Testbench base-path configuration through Laravel's supported application API.

## 1.0.5 - 2026-08-01

- Make standalone Testbench booting independent of the host application's base path.

## 1.0.4 - 2026-08-01

- Add a validated, machine-readable feature catalog for runtime discovery and diagnostics.
- Add standalone tests that keep feature metadata consistent and unique.

## 1.0.4 - 2026-08-01

- Add startup compatibility validation, status diagnostics, and atomic production registry caching.

## 1.0.0 - 2026-08-01

- Add canonical manifest discovery, validation, enablement, dependency sorting, and provider registration.
