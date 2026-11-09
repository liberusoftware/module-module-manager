<?php

namespace Liberu\Foundation\ModuleManager;

use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/** Administrators' enable and disable choices. Stored in the database so every application server shares them. */
final readonly class ModuleActivations
{
    public function __construct(private ConnectionResolverInterface $database) {}

    /**
     * Read during boot, when the table may not exist yet: no choices then, and the configured selection applies.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        try {
            return $this->database->connection()->table('module_activations')->pluck('enabled', 'module')
                ->map(fn (mixed $enabled): bool => (bool) $enabled)->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function set(string $module, bool $enabled): void
    {
        $now = now();

        $this->database->connection()->table('module_activations')->upsert(
            [['module' => $module, 'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now]],
            ['module'],
            ['enabled', 'updated_at'],
        );
    }

    /**
     * A module disabled in configuration stays disabled: that is the operator's override.
     *
     * @param  list<string>  $enabled
     * @param  list<string>  $disabled
     * @param  array<string, bool>  $choices
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function apply(array $enabled, array $disabled, array $choices): array
    {
        return [
            array_values(array_unique([...$enabled, ...array_keys(array_filter($choices))])),
            array_values(array_unique([...$disabled, ...array_keys(array_filter($choices, fn (bool $on): bool => ! $on))])),
        ];
    }
}
