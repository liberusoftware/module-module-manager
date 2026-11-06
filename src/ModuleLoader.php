<?php

namespace Liberu\Foundation\ModuleManager;

use Illuminate\Foundation\Application;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use RuntimeException;

/** Discovers, selects and validates one module at a time. Only a required module can stop the boot. */
final class ModuleLoader
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $enabled
     * @param  list<string>  $disabled
     * @param  list<string>  $required
     */
    public function __construct(
        private readonly ModuleValidator $validator,
        private readonly RegistryCache $cache,
        private readonly array $paths,
        private readonly array $enabled,
        private readonly array $disabled,
        private readonly array $required,
        private readonly bool $useCache = false,
        private readonly string $cachePath = '',
    ) {}

    public function load(): ModuleInventory
    {
        // A cache is written only after module:cache has validated, so a cached boot skips validation.
        if ($this->useCache && is_file($this->cachePath)) {
            return $this->select($this->cache->load($this->paths, true, $this->cachePath)->all(), [], validate: false);
        }

        [$registry, $problems] = (new ModuleDiscovery())->discoverTolerant($this->paths);

        return $this->select($registry->all(), $problems, validate: true);
    }

    /**
     * Every installed module's faults, booted or not. This is the deployment check.
     *
     * @return array<string, list<string>>
     */
    public function audit(): array
    {
        [$registry, $problems] = (new ModuleDiscovery())->discoverTolerant($this->paths);

        foreach ($this->validator->validate($registry, Application::VERSION) as $error) {
            $problems[$this->owner($error, $registry->all())][] = $error;
        }

        return $problems;
    }

    /**
     * @param  array<string, Manifest>  $manifests
     * @param  array<string, list<string>>  $problems
     */
    private function select(array $manifests, array $problems, bool $validate): ModuleInventory
    {
        foreach ($this->required as $name) {
            if (! isset($manifests[$name])) {
                throw RequiredModuleFailed::because($name, implode(' ', $problems[$name] ?? ['it is not installed.']));
            }
        }

        $excluded = array_values(array_diff($this->disabled, $this->required));
        $enabled = array_values(array_unique([...$this->enabled, ...$this->required]));
        $registry = new ModuleRegistry($manifests);

        // Excluding a module can break a module that needs it, so repeat until the set is stable.
        while (true) {
            try {
                $selected = [];
                foreach ($registry->resolve($enabled, $excluded) as $manifest) {
                    $selected[$manifest->name()] = $manifest;
                }
            } catch (DependencyResolutionFailed $exception) {
                $this->exclude($this->owner($exception->getMessage(), $manifests), $exception->getMessage(), $excluded, $problems);

                continue;
            }

            $errors = $validate ? $this->validator->validate(new ModuleRegistry($selected), Application::VERSION) : [];
            if ($errors === []) {
                break;
            }

            $owners = [];
            foreach ($errors as $error) {
                $owners[$this->owner($error, $manifests)][] = $error;
            }
            foreach ($owners as $name => $messages) {
                $this->exclude($name, implode(' ', $messages), $excluded, $problems);
            }
        }

        return new ModuleInventory(
            $manifests,
            $selected,
            $problems,
            $this->required,
            array_values(array_intersect($this->disabled, $this->required)),
        );
    }

    /**
     * @param  list<string>  $excluded
     * @param  array<string, list<string>>  $problems
     */
    private function exclude(string $name, string $problem, array &$excluded, array &$problems): void
    {
        if (in_array($name, $this->required, true)) {
            throw RequiredModuleFailed::because($name, $problem);
        }

        // Excluding it again would not change the set, so the selection would never settle.
        if ($name === 'unknown' || in_array($name, $excluded, true)) {
            throw new RuntimeException("Module selection could not settle: {$problem}");
        }

        $excluded[] = $name;
        $problems[$name][] = $problem;
    }

    /**
     * The validator and registry name their module first: `name: …`, or `Module [name] …`.
     *
     * @param  array<string, Manifest>  $manifests
     */
    private function owner(string $message, array $manifests): string
    {
        foreach (['/^([a-z0-9-]+): /', '/\[([a-z0-9-]+)\]/'] as $pattern) {
            if (preg_match($pattern, $message, $match) === 1 && isset($manifests[$match[1]])) {
                return $match[1];
            }
        }

        return 'unknown';
    }
}
