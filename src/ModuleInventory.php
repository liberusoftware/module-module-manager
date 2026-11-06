<?php

namespace Liberu\Foundation\ModuleManager;

/** What this deployment found, what it booted, and why anything else did not boot. */
final class ModuleInventory
{
    /**
     * @param  array<string, Manifest>  $manifests  every readable manifest, by module name
     * @param  array<string, Manifest>  $booted  in dependency order
     * @param  array<string, list<string>>  $problems  by module name, or directory when the manifest is unreadable
     * @param  list<string>  $required
     * @param  list<string>  $refusedDisables  required modules that the disabled list named
     */
    public function __construct(
        public readonly array $manifests,
        public array $booted,
        public array $problems = [],
        public readonly array $required = [],
        public readonly array $refusedDisables = [],
    ) {}

    public function isBooted(string $name): bool
    {
        return isset($this->booted[$name]);
    }

    public function isRequired(string $name): bool
    {
        return in_array($name, $this->required, true);
    }

    /** @return 'booted'|'invalid'|'disabled' */
    public function status(string $name): string
    {
        return match (true) {
            $this->isBooted($name) => 'booted',
            isset($this->problems[$name]) => 'invalid',
            default => 'disabled',
        };
    }

    /** An optional module whose provider threw: it is out, and the reason is on record. */
    public function markFailed(string $name, string $problem): void
    {
        unset($this->booted[$name]);
        $this->problems[$name][] = $problem;
    }

    /**
     * Every module, invalid ones included, for diagnostics.
     *
     * @return array<string, array{module: string, version: string, category: string, kind: string, status: string, problems: list<string>}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->manifests as $name => $manifest) {
            $rows[$name] = [
                'module' => $name,
                'version' => $manifest->version(),
                'category' => $manifest->category(),
                'kind' => $this->isRequired($name) ? 'required' : 'optional',
                'status' => $this->status($name),
                'problems' => $this->problems[$name] ?? [],
            ];
        }

        foreach (array_diff_key($this->problems, $this->manifests) as $name => $problems) {
            $rows[$name] = ['module' => $name, 'version' => '?', 'category' => '?', 'kind' => $this->isRequired($name) ? 'required' : 'optional', 'status' => 'invalid', 'problems' => $problems];
        }

        ksort($rows);

        return $rows;
    }
}
