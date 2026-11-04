<?php

namespace Liberu\Foundation\ModuleManager\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\PackageManifest;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;

final class ValidateModulesCommand extends Command
{
    protected $signature = 'module:validate {--strict : Also fail when Laravel package discovery registers a module}';

    protected $description = 'Validate every installed module, booted or not: manifests, Composer metadata, providers, compatibility, and dependency ordering';

    public function handle(ModuleLoader $loader, ModuleInventory $inventory, PackageManifest $packages): int
    {
        $errors = [];

        foreach ($loader->audit() as $name => $problems) {
            foreach ($problems as $problem) {
                $errors[] = str_starts_with($problem, "{$name}: ") ? $problem : "{$name}: {$problem}";
            }
        }

        foreach ($inventory->refusedDisables as $name) {
            $errors[] = "{$name}: the disabled list names a required module, so it stays on.";
        }

        foreach ($inventory->manifests as $name => $manifest) {
            if (in_array($manifest->provider(), $packages->providers(), true)) {
                $message = "{$name}: Laravel package discovery registers its provider, which bypasses the module manager. Add its package to extra.laravel.dont-discover.";

                if ($this->option('strict')) {
                    $errors[] = $message;
                } else {
                    $this->warn($message);
                }
            }
        }

        if ($errors !== []) {
            foreach (array_values(array_unique($errors)) as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('All discovered modules are valid.');

        return self::SUCCESS;
    }
}
