<?php

declare(strict_types=1);

namespace App\Updates;

use App\Analytics\InstanceHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Finds published releases. The public source of truth is the list of GitHub releases of the repository: a
 * release exists once its archive and its checksums are attached, so nothing else has to be published.
 */
final class ReleaseFeed
{
    public const STATE = 'update_check';

    public function __construct(private readonly InstanceHealth $health) {}

    public function enabled(): bool
    {
        return (bool) config('perysee.update.check');
    }

    public function currentVersion(): string
    {
        return Version::normalize((string) config('perysee.version'));
    }

    /**
     * The newest stable release, or null when there is none.
     */
    public function latest(): ?ReleaseInfo
    {
        return $this->fetch($this->api().'/releases/latest');
    }

    public function forTag(string $tag): ?ReleaseInfo
    {
        return $this->fetch($this->api().'/releases/tags/'.rawurlencode(Version::tag($tag)));
    }

    /**
     * Asks GitHub and remembers the answer. A failed check keeps the previous answer and records why it failed.
     *
     * @return array{checked_at: string, latest: ?array<string, mixed>, error: ?string}
     */
    public function refresh(): array
    {
        $previous = $this->stored();
        try {
            $state = ['checked_at' => now('UTC')->toIso8601String(), 'latest' => $this->latest()?->toArray(), 'error' => null];
        } catch (UpdateException $exception) {
            $state = ['checked_at' => now('UTC')->toIso8601String(), 'latest' => $previous['latest'] ?? null, 'error' => $exception->getMessage()];
        }
        $this->health->put(self::STATE, $state);

        return $state;
    }

    /**
     * @return array{checked_at: ?string, latest: ?array<string, mixed>, error: ?string}
     */
    public function stored(): array
    {
        return (array) $this->health->get(self::STATE, []) + ['checked_at' => null, 'latest' => null, 'error' => null];
    }

    public function available(): ?ReleaseInfo
    {
        $latest = $this->stored()['latest'];
        if ($latest === null) {
            return null;
        }
        $release = ReleaseInfo::fromArray($latest);

        return Version::isNewer($release->tag, $this->currentVersion()) ? $release : null;
    }

    private function api(): string
    {
        return 'https://api.github.com/repos/'.config('perysee.update.repository');
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Perysee/'.$this->currentVersion()])->timeout(10)->connectTimeout(5);
    }

    private function fetch(string $url): ?ReleaseInfo
    {
        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException) {
            throw new UpdateException('GitHub cannot be reached from this server.');
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpdateException('GitHub answered with HTTP '.$response->status().'. If the repository is private, releases cannot be listed without a token.');
        }
        $data = $response->json();
        if (! is_array($data) || ($data['draft'] ?? false)) {
            return null;
        }
        $tag = (string) ($data['tag_name'] ?? '');
        if (! Version::isValid($tag)) {
            return null;
        }
        $assets = [];
        foreach ($data['assets'] ?? [] as $asset) {
            $assets[(string) ($asset['name'] ?? '')] = (string) ($asset['browser_download_url'] ?? '');
        }
        $archive = $assets[PackageVerifier::ARCHIVE] ?? '';
        $checksums = $assets['SHA256SUMS'] ?? '';
        if (! str_starts_with($archive, 'https://') || ! str_starts_with($checksums, 'https://')) {
            return null;
        }

        return new ReleaseInfo(Version::tag($tag), $archive, $checksums, $data['published_at'] ?? null, $data['html_url'] ?? null);
    }
}
