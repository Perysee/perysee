<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Manifest;

/**
 * One module offered by the Perysee catalog. The fields listed in {@see self::SIGNED} are covered by the
 * Ed25519 signature; the name and the description are display text and are not.
 */
final readonly class CatalogEntry
{
    /** Signed fields, in the order of the canonical payload (sorted by name). */
    public const SIGNED = ['api', 'core', 'downloadUrl', 'sha256', 'slug', 'stability', 'tier', 'version'];

    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $version,
        public string $requiredPlan,
        public string $stability,
        public string $tier,
        public int $api,
        public string $core,
        public ?string $downloadUrl,
        public ?string $sha256,
        public ?string $signature,
        public ?string $keyId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $field, string $pattern): string => is_string($data[$field] ?? null) && preg_match($pattern, $data[$field]) === 1
            ? $data[$field]
            : throw new CatalogException("The catalog entry has an invalid \"{$field}\".");
        $optional = static fn (string $field, string $pattern): ?string => ($data[$field] ?? null) === null
            ? null
            : (is_string($data[$field]) && preg_match($pattern, $data[$field]) === 1 ? $data[$field] : throw new CatalogException("The catalog entry has an invalid \"{$field}\"."));
        if (! is_int($data['api'] ?? null) || $data['api'] < 1) {
            throw new CatalogException('The catalog entry has an invalid "api".');
        }
        $version = '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D';

        return new self(
            slug: $text('slug', '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D'),
            name: is_string($data['name'] ?? null) ? mb_substr(trim($data['name']), 0, 100) : '',
            description: is_string($data['description'] ?? null) ? mb_substr(trim($data['description']), 0, 300) : '',
            version: $text('version', $version),
            requiredPlan: $text('requiredPlan', '/^(free|pro|agency)$/D'),
            stability: $text('stability', '/^('.implode('|', Manifest::STABILITY_LEVELS).')$/D'),
            tier: $text('tier', '/^official$/D'),
            api: $data['api'],
            core: $text('core', $version),
            downloadUrl: $optional('downloadUrl', '#^https://[A-Za-z0-9.-]+(?::\d+)?/[A-Za-z0-9._~/%+-]+$#D'),
            sha256: $optional('sha256', '/^[0-9a-f]{64}$/D'),
            signature: $optional('signature', '/^[A-Za-z0-9+\/]+={0,2}$/D'),
            keyId: $optional('keyId', '/^[A-Za-z0-9._-]{1,40}$/D'),
        );
    }

    /**
     * What the maintainer signs: the signed fields as compact JSON, keys sorted by name. scripts/sign-module.mjs in
     * the API produces the same bytes.
     *
     * @param  array<string, mixed>  $data
     */
    public static function canonicalPayload(array $data): string
    {
        $payload = [];
        foreach (self::SIGNED as $field) {
            $payload[$field] = $data[$field] ?? null;
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Only a free module that carries everything needed to download and verify it can be installed from the catalog.
     * A paid module is listed, but its archive is not distributed through this public channel.
     */
    public function isDownloadable(): bool
    {
        return $this->requiredPlan === 'free'
            && $this->downloadUrl !== null
            && $this->sha256 !== null
            && $this->signature !== null
            && $this->keyId !== null;
    }

    public function payload(): string
    {
        return self::canonicalPayload([
            'api' => $this->api, 'core' => $this->core, 'downloadUrl' => $this->downloadUrl, 'sha256' => $this->sha256,
            'slug' => $this->slug, 'stability' => $this->stability, 'tier' => $this->tier, 'version' => $this->version,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug, 'name' => $this->name, 'description' => $this->description, 'version' => $this->version,
            'requiredPlan' => $this->requiredPlan, 'stability' => $this->stability, 'tier' => $this->tier, 'api' => $this->api,
            'core' => $this->core, 'downloadUrl' => $this->downloadUrl, 'sha256' => $this->sha256,
            'signature' => $this->signature, 'keyId' => $this->keyId,
        ];
    }
}
