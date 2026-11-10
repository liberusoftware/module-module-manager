<?php

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagement;
use Liberu\Foundation\ModuleManager\ModuleValidator;

final class ManagedProvider extends ServiceProvider {}

beforeEach(function () {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_09_000000_create_module_activations_table.php')->up();
    $this->root = sys_get_temp_dir().'/managed-'.bin2hex(random_bytes(5));
    mkdir($this->root, 0777, true);
    $this->composer = require dirname(__DIR__, 2).'/vendor/composer/installed.php';
    $this->original = $this->composer;
});

afterEach(function () {
    InstalledVersions::reload($this->original);
    (new Filesystem())->deleteDirectory($this->root);
});

function managedModule(object $test, string $name, array $manifest = [], ?string $composerVersion = '1.0.0'): void
{
    $dir = "{$test->root}/{$name}";
    mkdir($dir, 0777, true);
    file_put_contents("{$dir}/module.json", json_encode($manifest + [
        'name' => $name, 'display_name' => ucfirst($name), 'description' => 'Test', 'version' => '1.0.0', 'category' => 'capability',
        'provider' => ManagedProvider::class, 'requires' => ['packages' => []], 'capabilities' => [],
        'features' => ['Test'], 'default_enabled' => false, 'module_type' => 'feature',
    ], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/composer.json", json_encode(['name' => "local/{$name}", 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]]], JSON_THROW_ON_ERROR));

    if ($composerVersion !== null) {
        $test->composer['versions']["local/{$name}"] = ['pretty_version' => $composerVersion, 'version' => $composerVersion.'.0', 'reference' => null, 'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false];
        InstalledVersions::reload($test->composer);
    }
}

function managedInventory(object $test, array $required = [], array $enabled = [], array $disabled = []): ModuleInventory
{
    [$enabled, $disabled] = ModuleActivations::apply($enabled, $disabled, app(ModuleActivations::class)->all());

    return (new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$test->root], $enabled, $disabled, $required))->load();
}

function management(object $test, array $required = [], array $enabled = [], array $disabled = [], array $discovered = []): ModuleManagement
{
    $packages = new PackageManifest(new Filesystem(), base_path(), "{$test->root}/packages.php");
    $packages->manifest = ['local/discovered' => ['providers' => $discovered]];

    return new ModuleManagement(managedInventory($test, $required, $enabled, $disabled), app(ModuleActivations::class), new ModuleValidator(), $packages, $enabled, $disabled);
}

it('lists only modules Composer installed, with the version Composer installed', function () {
    managedModule($this, 'alpha', ['version' => '2.3.4'], composerVersion: 'v2.3.4');
    managedModule($this, 'copied-in', composerVersion: null);

    $modules = management($this)->modules();

    expect($modules)->toHaveKey('alpha')->not->toHaveKey('copied-in')
        ->and($modules['alpha'])->toMatchArray(['name' => 'Alpha', 'version' => '2.3.4', 'status' => 'disabled', 'locked' => null]);
});

it('lists only feature modules, never infrastructure', function () {
    managedModule($this, 'alpha');
    managedModule($this, 'platform', ['module_type' => 'infrastructure', 'default_enabled' => true]);
    managedModule($this, 'undeclared', ['module_type' => null, 'default_enabled' => true]);

    expect(array_keys(management($this)->modules()))->toBe(['alpha'])
        ->and(fn () => management($this)->disable('platform'))->toThrow(ModuleActivationRefused::class, 'infrastructure')
        ->and(fn () => management($this)->disable('undeclared'))->toThrow(ModuleActivationRefused::class, 'infrastructure')
        ->and(managedInventory($this)->isBooted('platform'))->toBeTrue();
});

it('rejects a manifest with an unknown module type', function () {
    managedModule($this, 'odd', ['module_type' => 'plugin']);

    expect(fn () => Manifest::fromFile("{$this->root}/odd/module.json"))->toThrow(InvalidManifest::class, 'module_type');
});

it('enables a module so the next boot registers it', function () {
    managedModule($this, 'alpha');

    expect(managedInventory($this)->isBooted('alpha'))->toBeFalse();

    management($this)->enable('alpha');

    expect(managedInventory($this)->isBooted('alpha'))->toBeTrue()
        ->and(management($this)->modules()['alpha']['status'])->toBe('enabled');
});

it('disables a default-enabled module so the next boot does not register it, and leaves Composer alone', function () {
    managedModule($this, 'alpha', ['default_enabled' => true]);
    expect(managedInventory($this)->isBooted('alpha'))->toBeTrue();

    management($this)->disable('alpha');

    expect(managedInventory($this)->isBooted('alpha'))->toBeFalse()
        ->and(InstalledVersions::isInstalled('local/alpha'))->toBeTrue()
        ->and(is_file("{$this->root}/alpha/module.json"))->toBeTrue()
        ->and(management($this)->modules()['alpha']['status'])->toBe('disabled');
});

it('can enable a module again after disabling it', function () {
    managedModule($this, 'alpha', ['default_enabled' => true]);

    management($this)->disable('alpha');
    management($this)->enable('alpha');

    expect(managedInventory($this)->isBooted('alpha'))->toBeTrue();
});

it('refuses to disable a required module', function () {
    managedModule($this, 'core');

    expect(fn () => management($this, required: ['core'])->disable('core'))->toThrow(ModuleActivationRefused::class, 'requires this module');
    expect(DB::table('module_activations')->count())->toBe(0);
});

it('keeps a module the operator disabled in configuration off', function () {
    managedModule($this, 'alpha');

    expect(fn () => management($this, disabled: ['alpha'])->enable('alpha'))->toThrow(ModuleActivationRefused::class, 'MODULES_DISABLED');
});

it('refuses to disable a module another enabled module needs', function () {
    managedModule($this, 'base', ['default_enabled' => true, 'capabilities' => ['base.thing']]);
    managedModule($this, 'top', ['default_enabled' => true, 'requires' => ['packages' => [], 'capabilities' => ['base.thing' => '^1.0']]]);

    expect(fn () => management($this)->disable('base'))->toThrow(ModuleActivationRefused::class, '[top]');
    expect(managedInventory($this)->isBooted('base'))->toBeTrue();
});

it('refuses to enable a module whose dependency is off', function () {
    managedModule($this, 'base', ['capabilities' => ['base.thing']]);
    managedModule($this, 'top', ['requires' => ['packages' => [], 'capabilities' => ['base.thing' => '^1.0']]]);

    expect(fn () => management($this)->enable('top'))->toThrow(ModuleActivationRefused::class, 'base.thing');
});

it('still allows changes while an unrelated module is broken', function () {
    managedModule($this, 'broken', ['default_enabled' => true, 'provider' => 'Missing\\Provider']);
    managedModule($this, 'alpha');

    management($this)->enable('alpha');

    $modules = management($this)->modules();
    expect($modules['alpha']['status'])->toBe('enabled')->and($modules['broken']['status'])->toBe('failed');
});

it('locks a module Laravel auto-discovers, because disabling it would not stop it registering', function () {
    managedModule($this, 'alpha', ['default_enabled' => true]);

    $management = management($this, discovered: [ManagedProvider::class]);

    expect($management->modules()['alpha']['locked'])->toContain('dont-discover')
        ->and(fn () => $management->disable('alpha'))->toThrow(ModuleActivationRefused::class, 'dont-discover');
});

it('refuses a module Composer did not install', function () {
    managedModule($this, 'copied-in', composerVersion: null);

    expect(fn () => management($this)->enable('copied-in'))->toThrow(ModuleActivationRefused::class, 'not installed through Composer');
});

it('falls back to the configured selection before the table exists', function () {
    Schema::drop('module_activations');

    expect(app(ModuleActivations::class)->all())->toBe([]);
});
