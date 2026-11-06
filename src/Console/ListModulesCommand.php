<?php

namespace Liberu\Foundation\ModuleManager\Console;

use Illuminate\Console\Command;
use Liberu\Foundation\ModuleManager\ModuleInventory;

final class ListModulesCommand extends Command
{
    protected $signature = 'module:list {--json : Machine-readable output}';

    protected $description = 'List discovered modules, whether each is required, and whether it booted';

    public function handle(ModuleInventory $inventory): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(array_values($inventory->rows()), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Module', 'Version', 'Category', 'Enabled', 'Capabilities', 'Features', 'Kind', 'Status', 'Problems'], array_map(
            function (array $row) use ($inventory): array {
                $manifest = $inventory->manifests[$row['module']] ?? null;

                return [
                    $row['module'],
                    $row['version'],
                    $row['category'],
                    $inventory->isBooted($row['module']) ? 'yes' : 'no',
                    implode(', ', $manifest?->capabilities() ?? []),
                    count($manifest?->features() ?? []),
                    $row['kind'],
                    $row['status'],
                    implode(' ', $row['problems']),
                ];
            },
            array_values($inventory->rows()),
        ));

        return self::SUCCESS;
    }
}
