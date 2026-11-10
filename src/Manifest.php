<?php

namespace Liberu\Foundation\ModuleManager;

use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;

final readonly class Manifest
{
    public const CATEGORIES = ['foundation', 'contracts', 'capability', 'adapter', 'product', 'presentation', 'distribution'];

    /** A feature is something an administrator switches on and off; infrastructure is what the application is built on. */
    public const MODULE_TYPES = ['feature', 'infrastructure'];

    /** @param array<string, mixed> $data */
    private function __construct(public string $path, private array $data) {}

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (['name', 'display_name', 'description', 'version', 'category', 'provider', 'requires', 'capabilities', 'features', 'default_enabled'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidManifest("Manifest {$path} is missing required key [{$key}].");
            }
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $data['name'])) {
            throw new InvalidManifest("Manifest {$path} has an invalid module name.");
        }

        if (! is_array($data['requires']) || ! is_array($data['capabilities']) || ! is_array($data['features'])) {
            throw new InvalidManifest("Manifest {$path} has invalid requires, capabilities, or features metadata.");
        }

        if (! in_array($data['category'], self::CATEGORIES, true)) {
            throw new InvalidManifest("Manifest {$path} has an invalid category [{$data['category']}].");
        }

        if (! in_array($data['module_type'] ?? 'infrastructure', self::MODULE_TYPES, true)) {
            throw new InvalidManifest("Manifest {$path} module_type must be one of: ".implode(', ', self::MODULE_TYPES).'.');
        }

        if (! is_bool($data['default_enabled'])) {
            throw new InvalidManifest("Manifest {$path} default_enabled must be boolean.");
        }

        foreach ($data['capabilities'] as $capability) {
            if (! is_string($capability) || ! preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $capability)) {
                throw new InvalidManifest("Manifest {$path} has an invalid capability.");
            }
        }

        if ($data['features'] === []) {
            throw new InvalidManifest("Manifest {$path} must declare at least one feature.");
        }

        foreach ($data['features'] as $feature) {
            if (! is_string($feature) || trim($feature) !== $feature || $feature === '' || mb_strlen($feature) > 120) {
                throw new InvalidManifest("Manifest {$path} has an invalid feature.");
            }
        }

        if (count(array_unique(array_map('mb_strtolower', $data['features']))) !== count($data['features'])) {
            throw new InvalidManifest("Manifest {$path} has duplicate features.");
        }

        return new self(dirname($path), $data);
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function displayName(): string
    {
        return $this->data['display_name'];
    }

    public function category(): string
    {
        return $this->data['category'];
    }

    public function provider(): string
    {
        return $this->data['provider'];
    }

    /** Only a manifest that declares "module_type": "feature" is one; without the key a module is infrastructure. */
    public function isFeature(): bool
    {
        return ($this->data['module_type'] ?? 'infrastructure') === 'feature';
    }

    public function defaultEnabled(): bool
    {
        return $this->data['default_enabled'];
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_values($this->data['capabilities']);
    }

    /** @return list<string> */
    public function features(): array
    {
        return array_values($this->data['features']);
    }

    /** @return array<string, string> */
    public function requiredPackages(): array
    {
        $packages = $this->data['requires']['packages'] ?? [];

        return is_array($packages) ? $packages : [];
    }

    /** @return array<string, string> */
    public function requiredCapabilities(): array
    {
        $capabilities = $this->data['requires']['capabilities'] ?? [];

        return is_array($capabilities) ? $capabilities : [];
    }

    public function phpConstraint(): ?string
    {
        return $this->data['requires']['php'] ?? null;
    }

    public function laravelConstraint(): ?string
    {
        return $this->data['requires']['laravel'] ?? null;
    }

    /** @return list<class-string> */
    public function filamentPlugins(string $panel): array
    {
        $plugins = $this->data['presentation']['filament'][$panel] ?? [];

        return is_array($plugins) ? array_values(array_filter($plugins, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data + ['path' => $this->path];
    }
}
