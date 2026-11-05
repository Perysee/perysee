<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Str;

final readonly class Manifest
{
    private const ID_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D';

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D';

    /** How much a module can be relied on. A module that does not declare it is treated as beta, never as stable. */
    public const STABILITY_LEVELS = ['stable', 'beta', 'experimental'];

    public const DEFAULT_STABILITY = 'beta';

    /**
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $core,
        public array $requires = [],
        public string $entitlement = '',
        public string $description = '',
        public string $stability = self::DEFAULT_STABILITY,
        public int $api = ModuleApi::VERSION,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'version', 'core'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || trim($data[$field]) === '') {
                throw new InvalidManifest("Module manifest field \"{$field}\" is required.");
            }
        }
        if (! preg_match(self::ID_PATTERN, $data['id'])) {
            throw new InvalidManifest('Module id must be 1 to 40 lowercase letters, digits or dashes.');
        }
        foreach (['version', 'core'] as $field) {
            if (! preg_match(self::VERSION_PATTERN, $data[$field])) {
                throw new InvalidManifest("Module manifest field \"{$field}\" must be a version such as 1.2.0.");
            }
        }
        $requires = $data['requires'] ?? [];
        if (! is_array($requires) || array_filter($requires, fn ($id): bool => ! is_string($id) || ! preg_match(self::ID_PATTERN, $id))) {
            throw new InvalidManifest('Module manifest field "requires" must list module ids.');
        }

        $api = $data['api'] ?? ModuleApi::VERSION;
        if (! is_int($api) || $api < 1) {
            throw new InvalidManifest('Module manifest field "api" must be a whole number such as 1.');
        }
        $stability = $data['stability'] ?? self::DEFAULT_STABILITY;
        if (! is_string($stability) || ! in_array($stability, self::STABILITY_LEVELS, true)) {
            throw new InvalidManifest('Module manifest field "stability" must be one of: '.implode(', ', self::STABILITY_LEVELS).'.');
        }

        return new self(
            id: $data['id'],
            name: $data['name'],
            version: $data['version'],
            core: $data['core'],
            requires: array_values($requires),
            entitlement: is_string($data['entitlement'] ?? null) && $data['entitlement'] !== '' ? $data['entitlement'] : $data['id'],
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            stability: $stability,
            api: $api,
        );
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidManifest('Module manifest is missing or is not valid JSON.');
        }

        return self::fromArray($data);
    }

    public function namespaceSegment(): string
    {
        return Str::studly($this->id);
    }

    public function supportsCurrentApi(): bool
    {
        return $this->api === ModuleApi::VERSION;
    }

    public function isCompatibleWith(string $coreVersion): bool
    {
        return version_compare($coreVersion, $this->core, '>=');
    }
}
