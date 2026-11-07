<?php

namespace App\Analytics\Ingestion;

/**
 * What an event may carry as properties: a handful of short context attributes (a category, a payment method, a
 * price tier), never anything that identifies a person. The policy is fixed in code on purpose; see ADR 008.
 *
 * A property that breaks a soft limit is dropped or masked and reported, so the event itself is never lost.
 */
class PropertyPolicy
{
    public const MAX_PROPERTIES = 5;

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    public const MAX_VALUE_LENGTH = 64;

    public const MAX_BYTES = 1024;

    public const REDACTED = ':redacted';

    /** Keys whose value is masked, compared on the normalised key. A trailing star means "starts with". */
    private const DENIED = ['email', 'e_mail', 'mail', 'phone', 'tel', 'telephone', 'mobile', 'name', 'first_name', 'last_name', 'address', 'ip', 'user_id', 'customer_id', 'session', 'token', 'password', 'card', 'iban', 'ssn', 'birth*', 'zip', 'postal*'];

    /**
     * @param  array<string, mixed>  $props  A JSON object.
     * @return array{json: ?string, dropped: int, reasons: list<string>}
     */
    public function normalize(array $props): array
    {
        $kept = [];
        $dropped = 0;
        $reasons = [];
        $drop = function (string $reason) use (&$dropped, &$reasons): void {
            $dropped++;
            $reasons[$reason] = true;
        };
        foreach ($props as $rawKey => $raw) {
            $key = strtolower(trim((string) $rawKey));
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $drop('invalid_key');

                continue;
            }
            if (isset($kept[$key])) {
                $drop('duplicate_key');

                continue;
            }
            $value = $this->text($raw);
            if ($value === null) {
                $drop('invalid_value');

                continue;
            }
            if (count($kept) >= self::MAX_PROPERTIES) {
                $drop('too_many');

                continue;
            }
            if ($this->denied($key) || $this->personal($value)) {
                $value = self::REDACTED;
                $reasons['redacted'] = true;
                $dropped++;
            }
            $kept[$key] = $value;
        }
        ksort($kept);
        $json = $kept === [] ? null : json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        while ($json !== null && strlen($json) > self::MAX_BYTES) {
            array_pop($kept);
            $drop('too_large');
            $json = $kept === [] ? null : json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return ['json' => $json, 'dropped' => $dropped, 'reasons' => array_keys($reasons)];
    }

    /**
     * The value as short text, or null when it is empty, too long or not a scalar.
     */
    private function text(mixed $raw): ?string
    {
        $text = match (true) {
            is_string($raw) => trim($raw),
            is_bool($raw) => $raw ? 'true' : 'false',
            is_int($raw) => (string) $raw,
            is_float($raw) && is_finite($raw) => rtrim(rtrim(sprintf('%.12F', $raw), '0'), '.'),
            default => null,
        };
        if ($text === null || $text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        // The aggregates use this word for what exceeds their bounds.
        return $text === '(other)' ? 'other' : $text;
    }

    private function denied(string $key): bool
    {
        foreach (self::DENIED as $rule) {
            if (str_ends_with($rule, '*') ? str_starts_with($key, substr($rule, 0, -1)) : $key === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * Values that look like an address, a long number (phone, card, order) or an opaque identifier.
     */
    private function personal(string $value): bool
    {
        if (preg_match('/[\x00-\x1f\x7f@]|[a-f0-9]{32,}/i', $value) === 1) {
            return true;
        }
        if (preg_match('/\d(?:[ .\-]?\d){8,}/', $value) === 1) {
            return true;
        }
        $compact = strtoupper(str_replace(' ', '', $value));

        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $compact) === 1 && preg_match_all('/\d/', $compact) >= 8;
    }
}
