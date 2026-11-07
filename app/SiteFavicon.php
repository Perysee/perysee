<?php

namespace App;

use DOMDocument;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SiteFavicon
{
    private const MAX_BYTES = 262144;

    private int $requests = 0;

    /** @var array<string, array<int, string>> */
    private array $addresses = [];

    /** @return array{mime: string, body: string}|null */
    public function forDomain(string $domain): ?array
    {
        $cache = Cache::store('file');
        $key = 'site-favicon:v1:'.hash('sha256', strtolower($domain));
        $entry = $cache->get($key);
        if ($entry !== null) {
            return $entry['icon'];
        }

        $lock = $cache->lock($key.':lock', 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $entry = $cache->get($key);
            if ($entry !== null) {
                return $entry['icon'];
            }
            $this->requests = 0;
            $icon = $this->discover($domain);
            $cache->put($key, ['icon' => $icon], $icon ? now()->addDays(7) : now()->addHours(12));

            return $icon;
        } finally {
            $lock->release();
        }
    }

    /** @return array{mime: string, body: string}|null */
    private function discover(string $domain): ?array
    {
        $origin = 'https://'.$domain.'/';
        $response = $this->fetch($origin.'favicon.ico');
        if ($response && ($image = $this->image($response['body']))) {
            return $image;
        }
        $page = $this->fetch($origin);
        if (! $page || ! str_contains(strtolower($page['body']), '<link')) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($page['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
            if (! array_intersect($rel, ['icon', 'apple-touch-icon']) || ! $link->hasAttribute('href')) {
                continue;
            }
            $url = $this->absoluteUrl($page['url'], $link->getAttribute('href'));
            $response = $url ? $this->fetch($url) : null;
            if ($response && ($image = $this->image($response['body']))) {
                return $image;
            }
            if ($this->requests >= 6) {
                break;
            }
        }

        return null;
    }

    /** @return array{body: string, url: string}|null */
    private function fetch(string $url, int $redirects = 0): ?array
    {
        if ($this->requests >= 6 || strlen($url) > 2048 || ! extension_loaded('curl')) {
            return null;
        }
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) && ! $this->publicAddress($literal)) {
            return null;
        }
        $addresses = $this->addresses[$host] ??= $this->resolveAddresses($host);
        if (! $addresses) {
            return null;
        }
        foreach ($addresses as $address) {
            if (! $this->publicAddress($address)) {
                return null;
            }
        }
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $this->requests++;
        try {
            // Pin the validated address so a second DNS lookup cannot target a private network.
            $response = Http::withoutRedirecting()->setHandler(new CurlHandler)->connectTimeout(2)->timeout(3)
                ->withUserAgent('Perysee favicon cache')->withHeaders(['Accept' => 'image/*, text/html;q=0.5', 'Accept-Encoding' => 'identity'])
                ->withOptions([
                    'proxy' => '',
                    'decode_content' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]],
                    'progress' => function ($total, $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new RuntimeException('Favicon response is too large.');
                        }
                    },
                ])->get($url);
            if ($response->redirect()) {
                $response->close();
                $target = $this->absoluteUrl($url, $response->header('Location'));

                return $target && $redirects < 2 ? $this->fetch($target, $redirects + 1) : null;
            }
            if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                $response->close();

                return null;
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $body .= $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
            }
            $response->close();

            return strlen($body) <= self::MAX_BYTES ? compact('body', 'url') : null;
        } catch (ConnectionException|RuntimeException $exception) {
            return null;
        }
    }

    private function publicAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
            && ! ($packed && strlen($packed) === 16 && (
                substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff"
                // NAT64 (64:ff9b::/96 and 64:ff9b:1::/48) carries an IPv4 address that a gateway may route to an internal host,
                // and PHP counts these prefixes as global.
                || substr($packed, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8)
                || substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01"
            ));
    }

    /** @return array<int, string> */
    protected function resolveAddresses(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return [$literal];
        }
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $host)) {
            return [];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
    }

    private function absoluteUrl(string $base, string $relative): ?string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($relative));
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }

    /** @return array{mime: string, body: string}|null */
    private function image(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $size = @getimagesizefromstring($body);
        if (! $size || $size[0] > 1024 || $size[1] > 1024) {
            return null;
        }
        $mime = [IMAGETYPE_ICO => 'image/x-icon', IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'][$size[2]] ?? null;

        return $mime ? compact('mime', 'body') : null;
    }
}
