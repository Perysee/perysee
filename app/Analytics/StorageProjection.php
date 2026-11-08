<?php

namespace App\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Tells when the database will reach the storage quota the administrator entered: a least-squares line through the
 * daily totals of the last 30 days of snapshots. Nothing is projected from fewer than 7 days of measurements, and
 * without a quota the snapshots are not even read.
 */
class StorageProjection
{
    public const WINDOW_DAYS = 30;

    public const MINIMUM_DAYS = 7;

    /** The Health page warns when the quota is closer than this. */
    public const ALERT_DAYS = 30;

    public function __construct(private readonly InstanceHealth $health) {}

    public function quotaBytes(): ?int
    {
        $quota = $this->health->get('storage_quota_bytes');

        return is_numeric($quota) && $quota > 0 ? (int) $quota : null;
    }

    /**
     * status is one of no_quota, unavailable (too few days), reached, flat (not growing) and projected.
     *
     * @return array{quota_bytes: ?int, status: string, days: ?int, bytes_per_day: ?int, days_of_data: int, message: ?string}
     */
    public function forecast(): array
    {
        $quota = $this->quotaBytes();
        if ($quota === null) {
            return $this->answer(null, 'no_quota');
        }
        $series = $this->series();
        $count = count($series);
        if ($count === 0) {
            return $this->answer($quota, 'unavailable', daysOfData: 0, message: 'Estimate unavailable: no measurement has been recorded yet.');
        }
        $latest = $series[array_key_last($series)];
        if ($latest >= $quota) {
            return $this->answer($quota, 'reached', 0, daysOfData: $count, message: 'The storage quota has been reached.');
        }
        if ($count < self::MINIMUM_DAYS) {
            return $this->answer($quota, 'unavailable', daysOfData: $count, message: 'Estimate unavailable: at least '.self::MINIMUM_DAYS.' days of measurements are needed.');
        }
        $slope = self::slope($series);
        if ($slope <= 0) {
            return $this->answer($quota, 'flat', daysOfData: $count, message: 'The database is not growing: no date can be projected for the quota.');
        }
        // Rounded before ceil() so that an exact division is not pushed up a day by floating-point noise.
        $days = (int) ceil(round(($quota - $latest) / $slope, 6));

        return $this->answer($quota, 'projected', $days, (int) round($slope), $count, "At the current growth, the storage quota will be reached in {$days} ".($days === 1 ? 'day' : 'days').'.');
    }

    /** The Health warning, or null when there is nothing to say. */
    public function alert(): ?array
    {
        $forecast = $this->forecast();
        $near = $forecast['status'] === 'reached' || ($forecast['status'] === 'projected' && $forecast['days'] < self::ALERT_DAYS);

        return $near ? ['code' => 'storage', 'message' => $forecast['message']] : null;
    }

    /**
     * Bytes per day through the least-squares line, the days being placed on the calendar so that a missed snapshot
     * does not bend the slope.
     *
     * @param  array<int, int|float>  $series  total bytes by day number
     */
    public static function slope(array $series): float
    {
        $meanDay = array_sum(array_keys($series)) / count($series);
        $meanBytes = array_sum($series) / count($series);
        $covariance = $variance = 0.0;
        foreach ($series as $day => $bytes) {
            $covariance += ($day - $meanDay) * ($bytes - $meanBytes);
            $variance += ($day - $meanDay) ** 2;
        }

        return $variance > 0 ? $covariance / $variance : 0.0;
    }

    /**
     * Total size of the database for each day with a snapshot in the window, by day number since 1970.
     *
     * @return array<int, int>
     */
    private function series(): array
    {
        $since = now('UTC')->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $series = [];
        $rows = DB::table('table_size_snapshots')->where('taken_on', '>=', $since)->groupBy('taken_on')->orderBy('taken_on')->selectRaw('taken_on, SUM(data_bytes + index_bytes) AS bytes')->get();
        foreach ($rows as $row) {
            $series[intdiv((int) strtotime(substr((string) $row->taken_on, 0, 10).' UTC'), 86400)] = (int) $row->bytes;
        }

        return $series;
    }

    private function answer(?int $quota, string $status, ?int $days = null, ?int $perDay = null, int $daysOfData = 0, ?string $message = null): array
    {
        return ['quota_bytes' => $quota, 'status' => $status, 'days' => $days, 'bytes_per_day' => $perDay, 'days_of_data' => $daysOfData, 'message' => $message];
    }
}
