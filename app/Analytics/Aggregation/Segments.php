<?php

namespace App\Analytics\Aggregation;

use App\Analytics\InstanceHealth;
use App\Analytics\Reporting\Filters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Segments
{
    public function ingest(object $event, object $site): void
    {
        if (! DB::table('segment_events')->insertOrIgnore(['event_id' => $event->id, 'website_id' => $site->id, 'received_at' => $event->received_at])) {
            return;
        }
        $this->increment($event, $site, $event->event_type === 1 ? 'pageviews' : 'custom_events', 1);
        if ($event->event_type !== 1 || ! $event->session_key) {
            return;
        }
        $identity = ['website_id' => $site->id, 'session_key' => $event->session_key];
        $first = DB::table('segment_sessions')->where($identity)->first();
        if ($first && [Carbon::parse($first->first_seen_at)->getTimestampMs(), $first->first_event_id] <= [Carbon::parse($event->received_at)->getTimestampMs(), $event->id]) {
            return;
        }
        if ($first) {
            $this->increment((object) json_decode($first->event_data, true, flags: JSON_THROW_ON_ERROR), $site, 'sessions', -1);
        }
        $known = DB::table('measured_sessions')->where($identity)->value('first_seen_at');
        if ($known && Carbon::parse($known)->lt(Carbon::parse($event->received_at))) {
            return;
        }
        $this->increment($event, $site, 'sessions', 1);
        DB::table('segment_sessions')->updateOrInsert($identity, ['first_event_id' => $event->id, 'first_seen_at' => $event->received_at, 'event_data' => json_encode($event, JSON_THROW_ON_ERROR)]);
    }

    private function increment(object $event, object $site, string $metric, int $delta): void
    {
        $dimensions = app(Filters::class)->values($event);
        $json = json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $local = Carbon::parse($event->received_at, 'UTC')->setTimezone($site->timezone);
        foreach (['stats_segment_daily' => $local->copy()->startOfDay(), 'stats_segment_hourly' => $local->copy()->startOfHour()] as $table => $bucket) {
            $identity = ['website_id' => $site->id, 'bucket_start' => $bucket->utc()->toDateTimeString(), 'segment_hash' => hash('sha256', $json)];
            DB::table($table)->insertOrIgnore($identity + $dimensions + ['stat_date' => $local->toDateString(), 'dimensions' => $json]);
            $query = DB::table($table)->where($identity)->where('dimensions', $json);
            if ($delta < 0 && (int) (clone $query)->value($metric) < -$delta) {
                throw new RuntimeException('Segment session attribution cannot move outside retained statistics.');
            }
            if ($query->increment($metric, $delta) !== 1) {
                throw new RuntimeException('Segment identity collision.');
            }
        }
    }

    public function rebuild(int $limit = 500): int
    {
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($limit): int {
                $rows = DB::table('events')->whereNotNull('processed_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('segment_events')->whereColumn('event_id', 'events.id'))->orderBy('id')->limit($limit)->get();
                foreach ($rows as $event) {
                    $site = DB::table('websites')->where('id', $event->website_id)->whereNull('deleting_at')->first();
                    if ($site) {
                        $this->ingest($event, $site);
                    }
                }
                app(InstanceHealth::class)->put('segments_rebuild', ['at' => now('UTC')->toIso8601String(), 'processed' => $rows->count(), 'pending' => DB::table('events')->whereNotNull('processed_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('segment_events')->whereColumn('event_id', 'events.id'))->count()]);

                return $rows->count();
            }, 3);
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
