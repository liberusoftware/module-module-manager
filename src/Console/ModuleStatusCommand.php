<?php

namespace Liberu\Foundation\ModuleManager\Console;

use Illuminate\Console\Command;
use Liberu\Foundation\ModuleManager\ModuleInventory;

final class ModuleStatusCommand extends Command
{
    protected $signature = 'module:status {name}';

    protected $description = 'Inspect one installed module, its deployment state, dependencies, capabilities, features, and any problems';

    public function handle(ModuleInventory $inventory): int
    {
        $name = (string) $this->argument('name');
        $manifest = $inventory->manifests[$name] ?? null;

        if ($manifest === null && ! isset($inventory->problems[$name])) {
            $this->error("Module [{$name}] is not installed.");

            return self::FAILURE;
        }

        $this->table(['Property', 'Value'], [
            ['installed', 'yes'],
            ['enabled', $inventory->isBooted($name) ? 'yes' : 'no'],
            ['status', $inventory->status($name)],
            ['kind', $inventory->isRequired($name) ? 'required' : 'optional'],
            ['version', $manifest?->version() ?? '?'],
            ['provider', $manifest?->provider() ?? '?'],
            ['dependencies', json_encode($manifest?->requiredPackages() ?? [], JSON_THROW_ON_ERROR)],
            ['capabilities', implode(', ', $manifest?->capabilities() ?? [])],
            ['features', implode(PHP_EOL, $manifest?->features() ?? [])],
            ['path', $manifest->path ?? '?'],
            ['problems', implode(PHP_EOL, $inventory->problems[$name] ?? [])],
        ]);

        return self::SUCCESS;
    }
}
