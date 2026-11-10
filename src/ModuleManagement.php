<?php

namespace Liberu\Foundation\ModuleManager;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;

/** Turns Composer-installed modules on and off. Installing and removing them stays with Composer. */
final readonly class ModuleManagement
{
    /**
     * @param  list<string>  $enabled
     * @param  list<string>  $disabled
     */
    public function __construct(
        private ModuleInventory $inventory,
        private ModuleActivations $activations,
        private ModuleValidator $validator,
        private PackageManifest $packages,
        private array $enabled,
        private array $disabled,
    ) {}

    /** @return array<string, array{module: string, name: string, description: string, version: string, status: 'enabled'|'disabled'|'failed', locked: ?string, problems: list<string>}> */
    public function modules(): array
    {
        $choices = $this->activations->all();
        $modules = [];

        foreach ($this->inventory->manifests as $name => $manifest) {
            $package = $this->packageName($manifest);

            if ($package === null || ! $manifest->isFeature()) {
                continue;
            }

            $on = $this->isOn($name, $manifest, $choices);
            $problems = $this->inventory->problems[$name] ?? [];

            $modules[$name] = [
                'module' => $name,
                'name' => $manifest->displayName(),
                'description' => $manifest->description(),
                'version' => ltrim((string) InstalledVersions::getPrettyVersion($package), 'v'),
                'status' => ! $on ? 'disabled' : ($problems === [] ? 'enabled' : 'failed'),
                'locked' => $this->lockReason($name, $manifest),
                'problems' => $problems,
            ];
        }

        ksort($modules);

        return $modules;
    }

    public function enable(string $module): void
    {
        $this->change($module, true);
    }

    public function disable(string $module): void
    {
        $this->change($module, false);
    }

    private function change(string $module, bool $enabled): void
    {
        $manifest = $this->inventory->manifests[$module] ?? null;

        if ($manifest === null || $this->packageName($manifest) === null) {
            throw new ModuleActivationRefused("Module [{$module}] is not installed through Composer.");
        }

        if (! $manifest->isFeature()) {
            throw new ModuleActivationRefused("Module [{$module}] is infrastructure, not a feature module.");
        }

        if (($reason = $this->lockReason($module, $manifest)) !== null) {
            throw new ModuleActivationRefused($reason);
        }

        $this->assertBootable($module, $enabled);
        $this->activations->set($module, $enabled);
    }

    /** Refuses a change the next boot could not honour, so an administrator cannot break the application. */
    private function assertBootable(string $module, bool $enable): void
    {
        [$enabled, $disabled] = ModuleActivations::apply($this->enabled, $this->disabled, [...$this->activations->all(), $module => $enable]);
        $alreadyBroken = array_diff(array_keys($this->inventory->problems), [$module]);
        $registry = new ModuleRegistry($this->inventory->manifests);

        try {
            $selected = [];
            foreach ($registry->resolve([...$enabled, ...$this->inventory->required], array_diff([...$disabled, ...$alreadyBroken], $this->inventory->required)) as $manifest) {
                $selected[$manifest->name()] = $manifest;
            }
        } catch (DependencyResolutionFailed $exception) {
            throw new ModuleActivationRefused($exception->getMessage());
        }

        $errors = $this->validator->validate(new ModuleRegistry($selected), Application::VERSION);

        if ($errors !== []) {
            throw new ModuleActivationRefused(implode(' ', $errors));
        }
    }

    /** @param array<string, bool> $choices */
    private function isOn(string $name, Manifest $manifest, array $choices): bool
    {
        return match (true) {
            $name === 'module-manager', $this->inventory->isRequired($name) => true,
            in_array($name, $this->disabled, true) => false,
            default => $choices[$name] ?? ($manifest->defaultEnabled() || in_array($name, $this->enabled, true)),
        };
    }

    private function lockReason(string $name, Manifest $manifest): ?string
    {
        return match (true) {
            $name === 'module-manager', $this->inventory->isRequired($name) => 'This application requires this module.',
            in_array($name, $this->disabled, true) => 'Disabled by the MODULES_DISABLED setting.',
            in_array($manifest->provider(), $this->packages->providers(), true) => 'Laravel auto-discovers this module, so it would still register. Add its package to extra.laravel.dont-discover first.',
            default => null,
        };
    }

    private function packageName(Manifest $manifest): ?string
    {
        $composer = json_decode((string) file_get_contents($manifest->path.'/composer.json'), true);
        $package = is_array($composer) ? ($composer['name'] ?? null) : null;

        return is_string($package) && InstalledVersions::isInstalled($package) ? $package : null;
    }
}
