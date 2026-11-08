<?php

namespace App\Analytics\Ingestion;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use MaxMind\Db\Reader;
use Throwable;

class Geolocation
{
    private ?Reader $reader = null;

    public function path(): string
    {
        $custom = rtrim(config('perysee.geoip_directory', storage_path('app/private/geoip')), '/').'/country.mmdb';

        return config('perysee.geoip_path') ?: (is_file($custom) ? $custom : resource_path('geoip/country.mmdb'));
    }

    public function status(): array
    {
        try {
            $this->reader ??= new Reader($this->path());
            $metadata = $this->reader->metadata();
            if (! str_contains(strtolower($metadata->databaseType), 'country')) {
                throw new \RuntimeException('A country database is required.');
            }

            return ['available' => true, 'provider' => 'DB-IP Lite', 'built_at' => gmdate('Y-m-d', $metadata->buildEpoch), 'sha256' => $this->sha256()];
        } catch (Throwable) {
            return ['available' => false, 'provider' => 'DB-IP Lite', 'built_at' => null, 'sha256' => null];
        }
    }

    /**
     * The checksum is read from the cache while the file keeps its path, size and modification time: hashing the whole
     * database on every status (the Health page asks twice) is wasted work.
     */
    private function sha256(): string
    {
        $path = $this->path();
        $stamp = $path.'|'.filemtime($path).'|'.filesize($path);
        $known = Cache::store('file')->get('geoip:sha256');
        if (($known['stamp'] ?? null) === $stamp) {
            return $known['sha256'];
        }
        $sha256 = $this->digest($path);
        Cache::store('file')->forever('geoip:sha256', ['stamp' => $stamp, 'sha256' => $sha256]);

        return $sha256;
    }

    protected function digest(string $path): string
    {
        return hash_file('sha256', $path);
    }

    public function lookup(string $ip): array
    {
        try {
            $this->reader ??= new Reader($this->path());
            if (! str_contains(strtolower($this->reader->metadata()->databaseType), 'country')) {
                return ['country_code' => null, 'geo_available' => false];
            }
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            $data = $public ? $this->reader->get($ip) : null;
            $code = $data['country']['iso_code'] ?? null;

            return ['country_code' => is_string($code) && preg_match('/^[A-Z]{2}$/D', $code) ? $code : null, 'geo_available' => true];
        } catch (Throwable) {
            return ['country_code' => null, 'geo_available' => false];
        }
    }

    public function import(UploadedFile $file): void
    {
        try {
            $reader = new Reader($file->getRealPath());
            if (! str_contains(strtolower($reader->metadata()->databaseType), 'country')) {
                throw new \RuntimeException('Wrong database type.');
            }
            $reader->get('8.8.8.8');
            $reader->close();
        } catch (Throwable) {
            throw ValidationException::withMessages(['database' => 'Upload a valid DB-IP Country MMDB database. The existing database has been preserved.']);
        }
        Cache::store('file')->lock('geoip:import', 30)->block(5, function () use ($file): void {
            $directory = config('perysee.geoip_directory', storage_path('app/private/geoip'));
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $temporary = tempnam($directory, '.country-');
            try {
                if (! copy($file->getRealPath(), $temporary)) {
                    throw new \RuntimeException('Cannot stage country database.');
                }
                chmod($temporary, 0600);
                $target = $directory.'/country.mmdb';
                if (is_file($target) && ! copy($target, $directory.'/previous.mmdb')) {
                    throw new \RuntimeException('Cannot preserve previous database.');
                }
                if (! rename($temporary, $target)) {
                    throw new \RuntimeException('Cannot replace country database.');
                }
                $this->reader = null;
                Cache::store('file')->forget('geoip:sha256');
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });
    }
}
