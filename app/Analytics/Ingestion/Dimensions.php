<?php

namespace App\Analytics\Ingestion;

use App\Models\Website;
use Illuminate\Http\Request;

class Dimensions
{
    public function values(array $data, Request $request, ?Website $site = null): array
    {
        $host = null;
        if (! empty($data['ref'])) {
            $parsed = parse_url($data['ref']);
            if (is_array($parsed) && in_array($parsed['scheme'] ?? '', ['http', 'https'], true) && ! isset($parsed['user']) && ! isset($parsed['pass'])) {
                $candidate = strtolower($parsed['host'] ?? '');
                if (strlen($candidate) <= 253 && filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $host = $candidate;
                }
            }
        }
        $status = empty($data['ref']) ? 'absent' : ($host ? 'valid' : 'invalid');
        if ($host && in_array($host, $site?->allowed_domains ?? [], true)) {
            $host = null;
            $status = 'internal';
        }
        $agent = strtolower($request->userAgent() ?? '');
        $client = app(Device::class)->details($agent, $data['device_hint'] ?? null);

        return [
            'source_value' => app(Sources::class)->resolve($this->text($data['utm_source'] ?? null), $host, $status), 'referrer_status' => $status,
            'event_name' => $data['event_name'] ?? null, 'referrer_host' => $host,
            'utm_source' => $this->text($data['utm_source'] ?? null), 'utm_medium' => $this->text($data['utm_medium'] ?? null),
            'utm_campaign' => $this->text($data['utm_campaign'] ?? null), ...$client,
            'dimensions_available' => count(array_intersect(array_keys($data), ['ref', 'utm_source', 'utm_medium', 'utm_campaign'])) > 0, 'tracking_mode' => $data['mode'],
        ];
    }

    private function text(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/[\x00-\x1f\x7f@\\\\\/?#=&]|[a-f0-9]{32,}/i', $value)) {
            return ':redacted';
        }

        return $value;
    }
}
