<?php

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidator;

final class IsolatedGoodProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('isolated.good', true);
    }
}

final class IsolatedThrowingProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new RuntimeException('provider exploded');
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/isolated-'.bin2hex(random_bytes(5));
    mkdir($this->root, 0777, true);
    $this->installed = require dirname(__DIR__, 2).'/vendor/composer/installed.php';
    $this->original = $this->installed;
});

afterEach(function () {
    InstalledVersions::reload($this->original);
    (new Filesystem())->deleteDirectory($this->root);
});

function isolatedModule(object $test, string $name, array $manifest = [], ?string $raw = null, bool $installed = true): void
{
    $dir = "{$test->root}/{$name}";
    mkdir($dir, 0777, true);
    file_put_contents("{$dir}/module.json", $raw ?? json_encode($manifest + [
        'name' => $name, 'display_name' => $name, 'description' => 'Test', 'version' => '1.0.0', 'category' => 'capability',
        'provider' => IsolatedGoodProvider::class, 'requires' => ['packages' => []], 'capabilities' => [],
        'features' => ['Test'], 'default_enabled' => true,
    ], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/composer.json", json_encode([
        'name' => "local/{$name}", 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]],
    ], JSON_THROW_ON_ERROR));

    if ($installed) {
        $test->installed['versions']["local/{$name}"] = ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false];
        InstalledVersions::reload($test->installed);
    }
}

function isolatedLoader(object $test, array $required = [], array $enabled = [], array $disabled = [], bool $cached = false, string $cachePath = ''): ModuleLoader
{
    return new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$test->root], $enabled, $disabled, $required, $cached, $cachePath);
}

it('reports an unreadable manifest and still loads the others', function () {
    isolatedModule($this, 'good');
    isolatedModule($this, 'broken-json', raw: '{ not json');
    isolatedModule($this, 'missing-keys', raw: '{"name":"missing-keys"}');

    $inventory = isolatedLoader($this)->load();

    expect($inventory->isBooted('good'))->toBeTrue()
        ->and($inventory->status('broken-json'))->toBe('invalid')
        ->and($inventory->problems['missing-keys'][0])->toContain('missing required key')
        ->and(array_keys($inventory->rows()))->toContain('broken-json', 'good', 'missing-keys');
});

it('leaves out a module that fails validation, and says why', function () {
    isolatedModule($this, 'good');
    isolatedModule($this, 'no-provider', ['provider' => 'Missing\\Provider']);
    isolatedModule($this, 'old-laravel', ['requires' => ['laravel' => '^9.0', 'packages' => []]]);
    isolatedModule($this, 'not-installed', installed: false);

    $inventory = isolatedLoader($this)->load();

    expect($inventory->isBooted('good'))->toBeTrue()
        ->and(implode(' ', $inventory->problems['no-provider']))->toContain('not autoloadable')
        ->and(implode(' ', $inventory->problems['old-laravel']))->toContain('does not satisfy ^9.0')
        ->and(implode(' ', $inventory->problems['not-installed']))->toContain('not installed');
});

it('leaves out a module whose dependency was left out, and whatever needs that', function () {
    isolatedModule($this, 'base', ['provider' => 'Missing\\Provider', 'capabilities' => ['base.thing']]);
    isolatedModule($this, 'middle', ['capabilities' => ['middle.thing'], 'requires' => ['packages' => [], 'capabilities' => ['base.thing' => '^1.0']]]);
    isolatedModule($this, 'top', ['requires' => ['packages' => [], 'capabilities' => ['middle.thing' => '^1.0']]]);
    isolatedModule($this, 'good');

    $inventory = isolatedLoader($this)->load();

    expect($inventory->isBooted('good'))->toBeTrue()
        ->and($inventory->status('base'))->toBe('invalid')
        ->and($inventory->status('middle'))->toBe('invalid')
        ->and($inventory->status('top'))->toBe('invalid');
});

it('rejects the later of two modules claiming one capability', function () {
    isolatedModule($this, 'clash-a', ['capabilities' => ['same.thing']]);
    isolatedModule($this, 'clash-b', ['capabilities' => ['same.thing']]);

    $inventory = isolatedLoader($this)->load();

    expect($inventory->isBooted('clash-a'))->toBeTrue()
        ->and($inventory->problems['clash-b'][0])->toContain('same.thing');
});

it('still stops at the first problem through discover()', function () {
    isolatedModule($this, 'broken-json', raw: '{ not json');

    expect(fn () => (new ModuleDiscovery())->discover([$this->root]))->toThrow(JsonException::class);
});

it('keeps a required module on when disabled, and records the refusal', function () {
    isolatedModule($this, 'core');
    isolatedModule($this, 'extra');

    $inventory = isolatedLoader($this, required: ['core'], disabled: ['core', 'extra'])->load();

    expect($inventory->isBooted('core'))->toBeTrue()
        ->and($inventory->isBooted('extra'))->toBeFalse()
        ->and($inventory->status('extra'))->toBe('disabled')
        ->and($inventory->refusedDisables)->toBe(['core']);
});

it('stops with the name when a required module is missing or broken', function () {
    isolatedModule($this, 'needed', raw: '{"name":"needed"}');
    isolatedModule($this, 'unbootable', ['provider' => 'Missing\\Provider']);

    expect(fn () => isolatedLoader($this, required: ['absent'])->load())
        ->toThrow(RequiredModuleFailed::class, 'Required module [absent] cannot load: it is not installed.')
        ->and(fn () => isolatedLoader($this, required: ['needed'])->load())
        ->toThrow(RequiredModuleFailed::class, 'missing required key')
        ->and(fn () => isolatedLoader($this, required: ['unbootable'])->load())
        ->toThrow(RequiredModuleFailed::class, 'not autoloadable');
});

it('boots the same modules from a cache', function () {
    isolatedModule($this, 'good');
    $path = "{$this->root}/registry.cache";
    (new RegistryCache())->write((new ModuleDiscovery())->discover([$this->root]), $path);

    expect(array_keys(isolatedLoader($this, cached: true, cachePath: $path)->load()->booted))
        ->toBe(array_keys(isolatedLoader($this)->load()->booted));
});

it('isolates a provider that throws, through the real provider', function () {
    isolatedModule($this, 'good');
    isolatedModule($this, 'explodes', ['provider' => IsolatedThrowingProvider::class]);
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => true]);

    $this->app->register(ModuleManagerServiceProvider::class, true);

    $inventory = $this->app->make(ModuleInventory::class);
    expect($inventory->status('explodes'))->toBe('invalid')
        ->and($inventory->problems['explodes'][0])->toContain('provider exploded')
        ->and($this->app->bound('isolated.good'))->toBeTrue();
});

it('still fails fast by default, so current consumers see no change', function () {
    isolatedModule($this, 'broken-json', raw: '{ not json');
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => false]);

    expect(fn () => $this->app->register(ModuleManagerServiceProvider::class, true))->toThrow(JsonException::class);
});

it('rethrows a required provider that throws', function () {
    isolatedModule($this, 'explodes', ['provider' => IsolatedThrowingProvider::class]);
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => true, 'modules.required' => ['explodes']]);

    expect(fn () => $this->app->register(ModuleManagerServiceProvider::class, true))
        ->toThrow(RequiredModuleFailed::class, 'provider exploded');
});

it('reports through the commands', function () {
    isolatedModule($this, 'good');
    isolatedModule($this, 'broken-json', raw: '{ not json');
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => true, 'modules.disabled' => []]);
    $this->app->register(ModuleManagerServiceProvider::class, true);
    $this->app->forgetInstance(ModuleLoader::class);

    Artisan::call('module:list', ['--json' => true]);
    $rows = collect(json_decode(Artisan::output(), true))->keyBy('module');
    expect($rows['good']['status'])->toBe('booted')
        ->and($rows['broken-json']['status'])->toBe('invalid');

    expect(Artisan::call('module:validate'))->toBe(1)
        ->and(Artisan::output())->toContain('broken-json');
    expect(Artisan::call('module:status', ['name' => 'broken-json']))->toBe(0)
        ->and(Artisan::output())->toContain('invalid');
    expect(Artisan::call('module:status', ['name' => 'nothing']))->toBe(1);
    expect(Artisan::call('module:cache'))->toBe(1);
});

it('boots valid providers and exposes feature listing and cache lifecycle commands', function () {
    isolatedModule($this, 'good');
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => false, 'modules.cache_path' => $this->root.'/registry.cache']);
    $this->app->register(ModuleManagerServiceProvider::class, true);

    expect($this->app->bound('isolated.good'))->toBeTrue()
        ->and(Artisan::call('module:list'))->toBe(0)
        ->and(Artisan::output())->toContain('good', 'booted')
        ->and(Artisan::call('module:features', ['query' => 'test']))->toBe(0)
        ->and(Artisan::output())->toContain('good', 'Test')
        ->and(Artisan::call('module:cache'))->toBe(0)
        ->and(is_file($this->root.'/registry.cache'))->toBeTrue()
        ->and(Artisan::call('module:clear'))->toBe(0)
        ->and(is_file($this->root.'/registry.cache'))->toBeFalse()
        ->and(Artisan::call('module:validate'))->toBe(0)
        ->and(Artisan::output())->toContain('All discovered modules are valid.');
});

it('warns about bypassing discovery and refuses it in strict validation', function () {
    isolatedModule($this, 'good');
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => true]);
    $this->app->register(ModuleManagerServiceProvider::class, true);
    $packages = new PackageManifest(new Filesystem(), base_path(), $this->root.'/packages.php');
    $packages->manifest = ['local/good' => ['providers' => [IsolatedGoodProvider::class]]];
    $this->app->instance(PackageManifest::class, $packages);

    expect(Artisan::call('module:validate'))->toBe(0)
        ->and(Artisan::output())->toContain('bypasses the module manager')
        ->and(Artisan::call('module:validate', ['--strict' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('bypasses the module manager');
});

it('reports required modules that an administrator tried to disable', function () {
    isolatedModule($this, 'good');
    config(['modules.paths' => [$this->root], 'modules.isolate_failures' => true, 'modules.required' => ['good'], 'modules.disabled' => ['good']]);
    $this->app->register(ModuleManagerServiceProvider::class, true);

    expect(Artisan::call('module:validate'))->toBe(1)
        ->and(Artisan::output())->toContain('the disabled list names a required module');
});

it('audits metadata faults even for disabled modules', function () {
    isolatedModule($this, 'bad-metadata', ['provider' => 'AbsentProvider', 'default_enabled' => false]);

    expect(isolatedLoader($this)->audit()['bad-metadata'][0])->toContain('not autoloadable');
});

it('chooses the canonical checkout when a legacy directory is also installed', function () {
    isolatedModule($this, 'module-liberu-renamed', ['name' => 'liberu-renamed']);
    isolatedModule($this, 'liberu-renamed');
    [$registry, $problems] = (new ModuleDiscovery())->discoverTolerant([$this->root]);

    expect(array_keys($registry->all()))->toContain('liberu-renamed')->not->toContain('module-liberu-renamed')
        ->and($registry->get('liberu-renamed')->path)->toBe($this->root.'/liberu-renamed')
        ->and($problems)->toBe([]);
});

it('fails closed when cached ownership keys cannot identify a dependency failure', function () {
    isolatedModule($this, 'good', ['requires' => ['packages' => ['local/uninstalled' => '^1.0']]]);
    $manifest = Manifest::fromFile($this->root.'/good/module.json');
    $registry = new ModuleRegistry(['INVALID KEY' => $manifest]);
    $path = $this->root.'/invalid-ownership.cache';
    (new RegistryCache())->write($registry, $path);

    expect(fn () => isolatedLoader($this, cached: true, cachePath: $path)->load())
        ->toThrow(RuntimeException::class, 'Module selection could not settle:');
});
