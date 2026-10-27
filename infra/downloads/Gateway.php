<?php

declare(strict_types=1);

namespace PeryseeDownloads;

use Closure;
use RuntimeException;
use Throwable;

/** Standalone download service: no Laravel bootstrap or production dependencies. */
final class Gateway
{
    private const ASSETS = ['install.sh' => 'text/x-shellscript; charset=utf-8', 'install.php' => 'application/octet-stream', 'perysee.zip' => 'application/zip', 'SHA256SUMS' => 'text/plain; charset=utf-8'];

    private const VERSION = '/^v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D';

    private string $cache;

    private Closure $transport;

    /** @param array{origin: string, repository: string, token: string, cache_path: string, public_root?: string} $config */
    public function __construct(private array $config, ?Closure $transport = null)
    {
        $origin = parse_url($config['origin']);
        if (! $origin || ($origin['scheme'] ?? '') !== 'https' || empty($origin['host']) || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment']) || ! empty($origin['path']) || ! preg_match('/^[a-zA-Z0-9.-]+$/D', $origin['host']) || ! preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/D', $config['repository']) || ! preg_match('/^[a-zA-Z0-9_.-]+$/D', $config['token'])) {
            throw new RuntimeException('Invalid private download configuration.');
        }
        $this->directory($config['cache_path']);
        $this->cache = realpath($config['cache_path']);
        $public = realpath($config['public_root'] ?? __DIR__.'/public');
        if ($public && ($this->cache === $public || str_starts_with($this->cache, $public.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Download cache must be outside the public root.');
        }
        $this->transport = $transport ?? self::transfer(...);
    }

    /** @return array{status: int, headers: array<string, string>, body: string, file: ?string} */
    public function handle(string $method, string $uri): array
    {
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return $this->response(405, "Method not allowed.\n", ['Allow' => 'GET, HEAD']);
        }
        $bootstrap = $uri === '/';
        $alias = self::ASSETS[substr($uri, 1)] ?? null;
        $pinned = preg_match('~^/releases/(v[^/]+)/([^/]+)$~D', $uri, $match) && preg_match(self::VERSION, $match[1]) && isset(self::ASSETS[$match[2]]);
        if (! $bootstrap && ! $alias && ! $pinned) {
            return $this->response(404, $method === 'HEAD' ? '' : "Not found.\n");
        }
        try {
            $version = $pinned ? $match[1] : $this->latest();
            $base = $this->config['origin'].'/releases/'.$version;
            if ($alias) {
                return $this->response(302, '', ['Location' => $base.'/'.substr($uri, 1), 'Cache-Control' => 'public, max-age=60']);
            }
            $directory = $this->release($version);
            if ($bootstrap) {
                $body = "export PERYSEE_DOWNLOAD_URL='".$base."'\n".file_get_contents($directory.'/install.sh');

                return $this->response(200, $method === 'HEAD' ? '' : $body, ['Content-Type' => self::ASSETS['install.sh'], 'Content-Length' => (string) strlen($body), 'Cache-Control' => 'public, max-age=60']);
            }
            $name = $match[2];

            return $this->response(200, '', ['Content-Type' => self::ASSETS[$name], 'Content-Length' => (string) filesize($directory.'/'.$name), 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'public, max-age=31536000, immutable'], $method === 'HEAD' ? null : $directory.'/'.$name);
        } catch (Throwable) {
            return $this->response(503, $method === 'HEAD' ? '' : "Perysee downloads are temporarily unavailable. Please try again later.\n", ['Retry-After' => '60']);
        }
    }

    private function latest(): string
    {
        return $this->locked('latest', function (): string {
            $path = $this->cache.'/latest.json';
            $cached = is_file($path) ? json_decode(file_get_contents($path), true) : null;
            $valid = is_array($cached) && isset($cached['version'], $cached['at']) && preg_match(self::VERSION, $cached['version']) && ! str_contains($cached['version'], '-');
            if ($valid && $cached['at'] > time() - 60) {
                return $cached['version'];
            }
            try {
                $release = $this->metadata('latest');
                if ($release['prerelease'] || str_contains($release['tag_name'], '-')) {
                    throw new RuntimeException('Latest must be stable.');
                }
                $this->release($release['tag_name']);
                $temporary = tempnam($this->cache, '.latest-');
                try {
                    $this->write($temporary, json_encode(['version' => $release['tag_name'], 'at' => time()], JSON_THROW_ON_ERROR));
                    if (! rename($temporary, $path)) {
                        throw new RuntimeException('Cannot publish latest metadata.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }

                return $release['tag_name'];
            } catch (Throwable $exception) {
                if ($valid && is_dir($this->cache.'/releases/'.$cached['version'])) {
                    return $cached['version'];
                }
                throw $exception;
            }
        });
    }

    private function release(string $version): string
    {
        $cached = $this->cache.'/releases/'.$version;
        if (is_dir($cached)) {
            return $cached;
        }

        return $this->locked('releases', function () use ($version): string {
            $this->directory($this->cache.'/releases');
            $target = $this->cache.'/releases/'.$version;
            if (is_dir($target)) {
                return $target;
            }
            $release = $this->metadata('tags/'.$version);
            if ($release['tag_name'] !== $version) {
                throw new RuntimeException('Release version mismatch.');
            }
            $assets = [];
            foreach ($release['assets'] as $asset) {
                $name = $asset['name'] ?? '';
                if (isset(self::ASSETS[$name])) {
                    if (isset($assets[$name]) || ! is_int($asset['id']) || $asset['id'] < 1) {
                        throw new RuntimeException('Invalid release asset.');
                    }
                    $assets[$name] = $asset['id'];
                }
            }
            if (count($assets) !== count(self::ASSETS)) {
                throw new RuntimeException('Incomplete release.');
            }
            $staging = $this->cache.'/.release-'.$version;
            if (is_dir($staging)) {
                foreach (array_keys(self::ASSETS) as $name) {
                    if (is_file($staging.'/'.$name)) {
                        unlink($staging.'/'.$name);
                    }
                }
                rmdir($staging);
            }
            $this->directory($staging);
            try {
                foreach (['SHA256SUMS', 'install.sh', 'install.php', 'perysee.zip'] as $name) {
                    $url = $this->api().'assets/'.$assets[$name];
                    $this->fetch($url, $staging.'/'.$name, $name === 'perysee.zip' ? 536870912 : 1048576, true);
                }
                $manifest = file_get_contents($staging.'/SHA256SUMS');
                foreach (['install.sh', 'install.php', 'perysee.zip'] as $name) {
                    if (preg_match_all('/^([a-f0-9]{64})  '.preg_quote($name, '/').'\r?$/m', $manifest, $hashes) !== 1 || ! hash_equals($hashes[1][0], hash_file('sha256', $staging.'/'.$name))) {
                        throw new RuntimeException('Release checksum mismatch.');
                    }
                }
                if (! rename($staging, $target)) {
                    throw new RuntimeException('Cannot publish release cache.');
                }

                return $target;
            } finally {
                if (is_dir($staging)) {
                    foreach (array_keys(self::ASSETS) as $name) {
                        if (is_file($staging.'/'.$name)) {
                            unlink($staging.'/'.$name);
                        }
                    }
                    rmdir($staging);
                }
            }
        });
    }

    private function metadata(string $selector): array
    {
        $temporary = tempnam($this->cache, '.metadata-');
        try {
            $this->fetch($this->api().$selector, $temporary, 1048576);
            $release = json_decode(file_get_contents($temporary), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($release) || ($release['draft'] ?? true) !== false || ! isset($release['tag_name'], $release['assets'], $release['prerelease']) || ! is_string($release['tag_name']) || ! preg_match(self::VERSION, $release['tag_name']) || ! is_array($release['assets'])) {
                throw new RuntimeException('Invalid release metadata.');
            }

            return $release;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function api(): string
    {
        return 'https://api.github.com/repos/'.$this->config['repository'].'/releases/';
    }

    private function fetch(string $url, string $destination, int $limit, bool $asset = false): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->config['token'], 'Accept' => $asset ? 'application/octet-stream' : 'application/vnd.github+json', 'User-Agent' => 'Perysee-Downloads', 'X-GitHub-Api-Version' => '2022-11-28'];
        $result = ($this->transport)($url, $headers, $destination, $limit);
        if ($asset && in_array($result['status'], [301, 302, 303, 307, 308], true)) {
            $redirect = parse_url($result['location'] ?? '');
            if (! $redirect || ($redirect['scheme'] ?? '') !== 'https' || ! str_ends_with($redirect['host'] ?? '', '.githubusercontent.com') || isset($redirect['user']) || isset($redirect['pass']) || (isset($redirect['port']) && $redirect['port'] !== 443)) {
                throw new RuntimeException('Unexpected release asset destination.');
            }
            $result = ($this->transport)($result['location'], ['User-Agent' => 'Perysee-Downloads'], $destination, $limit);
        }
        clearstatcache(true, $destination);
        if ($result['status'] !== 200 || ! is_file($destination) || filesize($destination) > $limit) {
            throw new RuntimeException('Release download failed.');
        }
    }

    /** @return array{status: int, location: ?string} */
    private static function transfer(string $url, array $headers, string $destination, int $limit): array
    {
        $stream = fopen($destination, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Cannot write download.');
        }
        chmod($destination, 0600);
        $curl = curl_init($url);
        $location = null;
        $bytes = 0;
        try {
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => array_map(fn ($name, $value) => $name.': '.$value, array_keys($headers), $headers), CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (str_starts_with(strtolower($line), 'location:')) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($stream, $limit, &$bytes): int {
                $bytes += strlen($data);
                if ($bytes > $limit) {
                    return 0;
                }

                return fwrite($stream, $data);
            }]);
            if (curl_exec($curl) === false) {
                throw new RuntimeException('Upstream transfer failed.');
            }

            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'location' => $location];
        } finally {
            fclose($stream);
        }
    }

    private function locked(string $key, Closure $action): mixed
    {
        $lock = fopen($this->cache.'/'.$key.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open cache lock.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Download is already being prepared.');
            }

            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create private cache.');
        }
    }

    private function write(string $path, string $body): void
    {
        if (file_put_contents($path, $body) !== strlen($body)) {
            throw new RuntimeException('Cannot write cached metadata.');
        }
        chmod($path, 0600);
    }

    private function response(int $status, string $body, array $headers = [], ?string $file = null): array
    {
        return ['status' => $status, 'headers' => $headers + ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=utf-8'], 'body' => $body, 'file' => $file];
    }
}
