<?php

namespace App\Analytics\Ingestion;

use App\Support\Coordination;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps the "why is nothing arriving?" evidence: one row per site, hour and cause, incremented in place.
 * Writing is capped (20 per minute per site and cause, shared by every visitor of that site) so a flood of refused
 * requests costs the cache counter it always cost, not a database write each. The counts are therefore
 * "at least". Nothing here may change the answer given to the tracker.
 */
class RejectionLog
{
    /** Writes allowed per minute for one site and cause. */
    public const WRITES_PER_MINUTE = 20;

    public const RETENTION_DAYS = 7;

    public function record(CollectionRejected $rejection): void
    {
        $this->write($rejection->cause, $rejection->websiteId, $rejection->siteKey, $rejection->origin);
    }

    public function write(RejectionCause $cause, ?int $websiteId, ?string $siteKey = null, ?string $origin = null): void
    {
        try {
            if ($websiteId === null && $siteKey !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $siteKey)) {
                // Only requests that named a real site are filed under it; a forged key never reaches the table.
                $websiteId = DB::table('websites')->where('public_id', $siteKey)->whereNull('deleting_at')->value('id');
            }
            // The budget is per site (0 = no site), so the noise of one site cannot starve the evidence of another.
            if (! app(Coordination::class)->throttle('rejlog:'.(int) ($websiteId ?? 0).':'.$cause->value, self::WRITES_PER_MINUTE, 60)) {
                return;
            }
            $this->upsert($cause, (int) ($websiteId ?? 0), in_array($cause, [RejectionCause::DomainNotAllowed, RejectionCause::UnknownSite], true) ? $this->host($origin) : null);
        } catch (Throwable) {
            // A diagnostic must never replace the answer given to the tracker.
        }
    }

    /**
     * Notes that a signed installation test reached the collector and the database. Unlike a refusal, a failure
     * here is not swallowed: the test must tell that the database could not be written.
     */
    public function probe(int $websiteId): void
    {
        $this->upsert(RejectionCause::ProbeOk, $websiteId, null);
    }

    private function upsert(RejectionCause $cause, int $websiteId, ?string $host): void
    {
        $now = now('UTC');
        $update = ['hits' => DB::raw('hits + 1'), 'last_at' => $now->format('Y-m-d H:i:s')];
        if ($host !== null) {
            $update['sample_host'] = $host;
        }
        DB::table('collection_diagnostics')->upsert([[
            'website_id' => $websiteId, 'bucket_start' => $now->copy()->startOfHour()->format('Y-m-d H:i:s'), 'cause' => $cause->value,
            'hits' => 1, 'sample_host' => $host, 'first_at' => $now->format('Y-m-d H:i:s'), 'last_at' => $now->format('Y-m-d H:i:s'),
        ]], ['website_id', 'bucket_start', 'cause'], $update);
    }

    /** The host of an Origin header when it is a plain host name (no port, path, credentials or address of a visitor). */
    public function host(?string $origin): ?string
    {
        $host = strtolower((string) parse_url((string) $origin, PHP_URL_HOST));

        return $host !== '' && strlen($host) <= 253 && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) ? $host : null;
    }

    /** Deletes expired rows, a bounded batch at a time. Returns how many it removed. */
    public function prune(int $limit = 1000): int
    {
        $cutoff = now('UTC')->subDays(self::RETENTION_DAYS)->format('Y-m-d H:i:s');
        $keys = DB::table('collection_diagnostics')->where('bucket_start', '<', $cutoff)->orderBy('bucket_start')->limit($limit)->get(['website_id', 'bucket_start', 'cause']);
        foreach ($keys as $key) {
            DB::table('collection_diagnostics')->where((array) $key)->delete();
        }

        return $keys->count();
    }
}
