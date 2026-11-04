<?php

namespace Liberu\Foundation\ModuleManager;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Console\CacheModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ClearModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ListFeaturesCommand;
use Liberu\Foundation\ModuleManager\Console\ListModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ModuleStatusCommand;
use Liberu\Foundation\ModuleManager\Console\ValidateModulesCommand;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Throwable;

final class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modules.php', 'modules');
        $this->app->singleton(RegistryCache::class);
        $this->app->singleton(ModuleLoader::class, fn () => new ModuleLoader(
            $this->app->make(ModuleValidator::class),
            $this->app->make(RegistryCache::class),
            (array) config('modules.paths', [base_path('modules')]),
            (array) config('modules.enabled', []),
            (array) config('modules.disabled', []),
            (array) config('modules.required', []),
            (bool) config('modules.cache', false),
            (string) config('modules.cache_path'),
        ));

        if ((bool) config('modules.isolate_failures', false)) {
            $this->registerIsolating();

            return;
        }

        $this->app->singleton(ModuleRegistry::class, fn () => $this->app->make(RegistryCache::class)->load(
            (array) config('modules.paths', [base_path('modules')]),
            (bool) config('modules.cache', false),
            (string) config('modules.cache_path'),
        ));

        $registry = $this->app->make(ModuleRegistry::class);
        $modules = $registry->resolve(config('modules.enabled', []), config('modules.disabled', []));
        $selected = [];
        foreach ($modules as $module) {
            $selected[$module->name()] = $module;
        }

        $this->app->make(ModuleValidationGuard::class)
            ->ensureValid(new ModuleRegistry($selected), Application::VERSION);

        $this->app->instance(ModuleInventory::class, new ModuleInventory($registry->all(), $selected, [], (array) config('modules.required', [])));

        foreach ($modules as $module) {
            if ($module->name() !== 'module-manager') {
                $this->app->register($module->provider());
            }
        }
    }

    /** One module at a time: a failure leaves that module out, unless it is required. */
    private function registerIsolating(): void
    {
        $this->app->singleton(ModuleInventory::class, fn () => $this->app->make(ModuleLoader::class)->load());
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry($this->app->make(ModuleInventory::class)->manifests));

        $inventory = $this->app->make(ModuleInventory::class);

        foreach ($inventory->booted as $name => $manifest) {
            if ($name === 'module-manager') {
                continue;
            }

            try {
                $this->app->register($manifest->provider());
            } catch (Throwable $exception) {
                if ($inventory->isRequired($name)) {
                    throw RequiredModuleFailed::because($name, $exception->getMessage());
                }

                $inventory->markFailed($name, "Its provider failed to register: {$exception->getMessage()}");
            }
        }

        $this->app->booted(function () use ($inventory): void {
            foreach ($inventory->problems as $name => $problems) {
                Log::warning("Module [{$name}] was not booted.", ['problems' => $problems]);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/modules.php' => config_path('modules.php')], 'modules-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CacheModulesCommand::class, ClearModulesCommand::class, ListFeaturesCommand::class, ListModulesCommand::class, ModuleStatusCommand::class, ValidateModulesCommand::class]);
        }
    }
}
