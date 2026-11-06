<?php

namespace Liberu\Foundation\ModuleManager\Console;

use Illuminate\Console\Command;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleLoader;

final class CacheModulesCommand extends Command
{
    protected $signature = 'module:cache';

    protected $description = 'Validate and atomically cache the discovered module registry for deployment';

    public function handle(RegistryCache $cache, ModuleLoader $loader): int
    {
        // With isolate_failures a cached boot skips validation, so nothing unvalidated may be cached.
        if ((bool) config('modules.isolate_failures', false)) {
            foreach ($loader->audit() as $name => $problems) {
                $this->error("{$name}: ".implode(' ', $problems));

                return self::FAILURE;
            }
        }

        $registry = (new ModuleDiscovery())->discover((array) config('modules.paths'));
        $cache->write($registry, (string) config('modules.cache_path'));
        $this->info('Module registry cached.');

        return self::SUCCESS;
    }
}
