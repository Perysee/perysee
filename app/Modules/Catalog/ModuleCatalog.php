<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Analytics\InstanceHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The list of modules Perysee offers, read from the public catalog of the Perysee API. The instance sends nothing
 * about itself: no identifier, no site, no cookie, only the Perysee version in the user agent. A failed check keeps
 * the previous answer and records why it failed, like the release feed.
 */
final class ModuleCatalog
{
    public const STATE = 'module_catalog';

    public const SOURCES = 'module_sources';

    public const MAX_BYTES = 512 * 1024;

    public const MAX_ENTRIES = 200;

    public function __construct(private readonly InstanceHealth $health) {}

    public function enabled(): bool
    {
        return (bool) config('perysee.module_catalog.enabled');
    }

    /**
     * Asks the catalog and remembers the answer.
     *
     * @return array{checked_at: ?string, entries: list<array<string, mixed>>, error: ?string}
     */
    public function refresh(): array
    {
        if (! $this->enabled()) {
            return $this->stored();
        }
        $previous = $this->stored();
        try {
            $state = ['checked_at' => now('UTC')->toIso8601String(), 'entries' => $this->fetch(), 'error' => null];
        } catch (CatalogException $exception) {
            $state = ['checked_at' => now('UTC')->toIso8601String(), 'entries' => $previous['entries'], 'error' => $exception->getMessage()];
        }
        $this->health->put(self::STATE, $state);

        return $state;
    }

    /**
     * @return array{checked_at: ?string, entries: list<array<string, mixed>>, error: ?string}
     */
    public function stored(): array
    {
        $state = $this->health->get(self::STATE, []);

        return (is_array($state) ? $state : []) + ['checked_at' => null, 'entries' => [], 'error' => null];
    }

    /**
     * The entries of the last successful answer.
     *
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->stored()['entries'] as $data) {
            try {
                $entries[] = CatalogEntry::fromArray(is_array($data) ? $data : []);
            } catch (CatalogException) {
                // Skipped when it was fetched; a stored entry that no longer reads is simply left out.
            }
        }

        return $entries;
    }

    /**
     * Where the modules installed from the catalog came from, by module id.
     *
     * @return array<string, array{tier: string, version: string, sha256: string, at: string}>
     */
    public function sources(): array
    {
        $sources = $this->health->get(self::SOURCES, []);

        return is_array($sources) ? $sources : [];
    }

    public function recordSource(CatalogEntry $entry): void
    {
        $sources = $this->sources();
        $sources[$entry->slug] = ['tier' => $entry->tier, 'version' => $entry->version, 'sha256' => (string) $entry->sha256, 'at' => now('UTC')->toIso8601String()];
        $this->health->put(self::SOURCES, $sources);
    }

    public function entry(string $slug): ?CatalogEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(): array
    {
        $url = (string) config('perysee.module_catalog.url');
        $host = parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['127.0.0.1', 'localhost'], true);
        if (! str_starts_with($url, 'https://') && ! ($local && str_starts_with($url, 'http://'))) {
            throw new CatalogException('The module catalog address must use HTTPS.');
        }
        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException) {
            throw new CatalogException('The module catalog cannot be reached from this server.');
        } catch (Throwable $exception) {
            throw new CatalogException('The module catalog could not be read: '.$exception->getMessage());
        }
        if (! $response->successful()) {
            throw new CatalogException('The module catalog answered with HTTP '.$response->status().'.');
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new CatalogException('The module catalog answer is too large.');
        }
        $data = json_decode($body, true);
        if (! is_array($data) || ! is_array($data['data'] ?? null)) {
            throw new CatalogException('The module catalog answer is not a list of modules.');
        }
        $entries = [];
        foreach (array_slice($data['data'], 0, self::MAX_ENTRIES) as $item) {
            try {
                $entries[] = CatalogEntry::fromArray(is_array($item) ? $item : [])->toArray();
            } catch (CatalogException) {
                // One malformed entry must not hide the others.
            }
        }

        return $entries;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['Accept' => 'application/json', 'User-Agent' => 'Perysee/'.config('perysee.version')])->timeout(10)->connectTimeout(5);
    }
}
