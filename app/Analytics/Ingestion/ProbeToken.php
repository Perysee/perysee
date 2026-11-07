<?php

namespace App\Analytics\Ingestion;

/**
 * The short-lived, site-bound token of the "Test now" button of the installation check. It is a signature
 * over the public site identifier and an expiry, so the collector can recognise it without a lookup, and a
 * token for one site, or an expired one, is worth nothing.
 */
class ProbeToken
{
    public const HEADER = 'X-Perysee-Probe';

    public const LIFETIME_SECONDS = 600;

    /** @return array{token: string, expires_at: int} */
    public function issue(string $publicId): array
    {
        $expires = now('UTC')->timestamp + self::LIFETIME_SECONDS;

        return ['token' => $expires.'.'.$this->signature($publicId, $expires), 'expires_at' => $expires];
    }

    public function valid(mixed $token, string $publicId): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $parts)) {
            return false;
        }
        $expires = (int) $parts[1];
        $now = now('UTC')->timestamp;

        return $expires > $now && $expires <= $now + self::LIFETIME_SECONDS && hash_equals($this->signature($publicId, $expires), $parts[2]);
    }

    private function signature(string $publicId, int $expires): string
    {
        return hash_hmac('sha256', 'probe|'.strtolower($publicId).'|'.$expires, (string) config('app.key'));
    }
}
