<?php

namespace App\Analytics\Aggregation;

use App\Analytics\InstanceHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Aggregator
{
    private ?string $lock = null;

    private ?array $siteIds = null;

    public function run(int $batch = 500, int $budgetSeconds = 15, ?array $siteIds = null): int
    {
        if ($batch < 1 || $batch > 1000 || $budgetSeconds < 1 || $budgetSeconds > 300) {
            throw new RuntimeException('Batch must be 1–1000 and budget 1–300 seconds.');
        }
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            return 0;
        }
        $this->lock = $maria ? $name : null;
        $this->siteIds = $siteIds;
        $deadline = hrtime(true) + $budgetSeconds * 1000000000;
        $count = 0;
        try {
            do {
                $processed = DB::transaction(fn (): int => $this->batch($batch), 3);
                $count += $processed;
            } while ($processed > 0 && hrtime(true) < $deadline);
            app(InstanceHealth::class)->put('aggregate_success', now('UTC')->toIso8601String());
            app(InstanceHealth::class)->put('aggregate_error', null);

            return $count;
        } catch (Throwable $exception) {
            try {
                app(InstanceHealth::class)->put('aggregate_error', ['at' => now('UTC')->toIso8601String(), 'type' => class_basename($exception)]);
            } catch (Throwable) {
            }
            throw $exception;
        } finally {
            if ($this->lock) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lock]);
            }
            $this->lock = null;
            $this->siteIds = null;
        }
    }

    private function batch(int $limit): int
    {
        if ($this->lock && (int) DB::selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$this->lock])->owned !== 1) {
            throw new RuntimeException('Aggregation database lock was lost.');
        }
        $events = DB::table('events')->whereNull('processed_at')->when($this->siteIds !== null, fn ($query) => $query->whereIn('website_id', $this->siteIds))->orderBy('id')->limit($limit)->lock($this->lock ? 'FOR UPDATE SKIP LOCKED' : true)->get();
        if ($events->isEmpty()) {
            return 0;
        }
        $sites = DB::table('websites')->whereIn('id', $events->pluck('website_id')->unique())->get()->keyBy('id');
        $daily = $hourly = $dimensions = $sessionDeltas = [];
        foreach ($events as $event) {
            $site = $sites->get($event->website_id);
            if (! $site) {
                throw new RuntimeException('An event has no matching website; repair before aggregation.');
            }
            if ($site->deleting_at) {
                continue;
            }
            app(Segments::class)->ingest($event, $site);
            [$date, $hour] = $this->buckets($event->received_at, $site->timezone);
            $mode = $event->tracking_mode ?? ($event->session_key ? 'sessions' : 'unknown');
            $metrics = ['pageviews' => $event->event_type === 1 ? 1 : 0, 'custom_events' => $event->event_type === 2 ? 1 : 0,
                'sessions' => 0, 'session_pageviews' => $event->event_type === 1 && $mode === 'sessions' ? 1 : 0,
                'unknown_pageviews' => $event->event_type === 1 && $mode === 'unknown' ? 1 : 0,
                'country_pageviews' => $event->event_type === 1 && $event->geo_available ? 1 : 0,
                'dimension_pageviews' => $event->event_type === 1 && $event->dimensions_available ? 1 : 0];
            $this->group($daily, $site->id, $date, $metrics);
            $this->group($hourly, $site->id, $hour, $metrics, $date);
            if ($event->event_type === 1) {
                $values = ['path' => $event->path];
                if ($event->browser_name !== null) {
                    $values['browser'] = $event->browser_name;
                }
                if ($event->device_brand !== null) {
                    $values['device_brand'] = $event->device_brand;
                }
                if ($event->geo_available) {
                    $values['country'] = $event->country_code ?: 'Unknown';
                }
                if ($event->dimensions_available) {
                    $values += ['source' => $event->source_value ?? ($event->referrer_host ?: 'Direct'), 'device' => $event->device_type ?: 'unknown'];
                    if ($event->utm_campaign || $event->utm_source || $event->utm_medium) {
                        $values['campaign'] = json_encode(['source' => $event->utm_source, 'medium' => $event->utm_medium, 'campaign' => $event->utm_campaign], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                }
            } else {
                $values = ['event' => $event->event_name ?: 'unknown'];
            }
            foreach ($values as $type => $value) {
                $key = implode('|', [$site->id, $date, $type, hash('sha256', $value)]);
                $dimensions[$key] ??= ['website_id' => $site->id, 'stat_date' => $date, 'dimension_type' => $type, 'dimension_hash' => hash('sha256', $value), 'dimension_value' => $value, 'metric_count' => 0];
                $dimensions[$key]['metric_count']++;
            }
            if ($event->event_type === 1 && $event->session_key) {
                $query = DB::table('measured_sessions')->where('website_id', $site->id)->where('session_key', $event->session_key);
                $session = (clone $query)->first();
                if (! $session) {
                    DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => $event->session_key, 'first_seen_at' => $event->received_at, 'last_seen_at' => $event->received_at]);
                    $this->sessionDelta($sessionDeltas, $site->id, $date, $hour, 1);
                } else {
                    if (Carbon::parse($event->received_at)->lt(Carbon::parse($session->first_seen_at))) {
                        [$oldDate, $oldHour] = $this->buckets($session->first_seen_at, $site->timezone);
                        $this->sessionDelta($sessionDeltas, $site->id, $oldDate, $oldHour, -1);
                        $this->sessionDelta($sessionDeltas, $site->id, $date, $hour, 1);
                        $query->update(['first_seen_at' => $event->received_at]);
                    }
                    if (Carbon::parse($event->received_at)->gt(Carbon::parse($session->last_seen_at))) {
                        $query->update(['last_seen_at' => $event->received_at]);
                    }
                }
            }
        }
        $this->upsertCounters('stats_daily', $daily, ['website_id', 'stat_date']);
        $this->upsertCounters('stats_hourly', $hourly, ['website_id', 'bucket_start']);
        foreach ($dimensions as $row) {
            $identity = array_intersect_key($row, array_flip(['website_id', 'stat_date', 'dimension_type', 'dimension_hash']));
            DB::table('stats_dimension_daily')->insertOrIgnore(array_merge($row, ['metric_count' => 0]));
            $updated = DB::table('stats_dimension_daily')->where($identity)->where('dimension_value', $row['dimension_value'])->increment('metric_count', $row['metric_count']);
            if ($updated !== 1) {
                throw new RuntimeException('Dimension identity collision.');
            }
        }
        foreach ($sessionDeltas as $delta) {
            foreach (['stats_daily' => ['stat_date', $delta['date']], 'stats_hourly' => ['bucket_start', $delta['hour']]] as $table => [$column, $bucket]) {
                $query = DB::table($table)->where('website_id', $delta['site'])->where($column, $bucket);
                if ($delta['count'] < 0 && (int) (clone $query)->value('sessions') < -$delta['count']) {
                    throw new RuntimeException('Cannot reposition a session outside the retained statistics.');
                }
                $query->increment('sessions', $delta['count']);
            }
        }
        foreach ($sites as $site) {
            if (! $site->deleting_at) {
                app(InstanceHealth::class)->put('aggregate_site:'.$site->id, now('UTC')->toIso8601String());
            }
        }
        DB::table('events')->whereIn('id', $events->pluck('id'))->update(['processed_at' => now('UTC')->format('Y-m-d H:i:s.v')]);

        return $events->count();
    }

    private function buckets(string $timestamp, string $timezone): array
    {
        $local = Carbon::parse($timestamp, 'UTC')->setTimezone($timezone);
        $date = $local->toDateString();
        $hour = $local->copy()->startOfHour()->utc()->toDateTimeString();

        return [$date, $hour];
    }

    private function group(array &$groups, int $site, string $bucket, array $metrics, ?string $date = null): void
    {
        $key = $site.'|'.$bucket;
        $groups[$key] ??= ['website_id' => $site, $date === null ? 'stat_date' : 'bucket_start' => $bucket] + ($date === null ? [] : ['stat_date' => $date]) + array_fill_keys(array_keys($metrics), 0);
        foreach ($metrics as $metric => $value) {
            $groups[$key][$metric] += $value;
        }
    }

    private function upsertCounters(string $table, array $groups, array $keys): void
    {
        if ($groups === []) {
            return;
        }
        $expressions = [];
        foreach (['pageviews', 'custom_events', 'sessions', 'session_pageviews', 'unknown_pageviews', 'dimension_pageviews', 'country_pageviews'] as $metric) {
            $incoming = DB::getDriverName() === 'sqlite' ? 'excluded.'.$metric : 'VALUES('.$metric.')';
            $expressions[$metric] = DB::raw($metric.' + '.$incoming);
        }
        DB::table($table)->upsert(array_values($groups), $keys, $expressions);
    }

    private function sessionDelta(array &$deltas, int $site, string $date, string $hour, int $count): void
    {
        $key = $site.'|'.$hour;
        $deltas[$key] ??= ['site' => $site, 'date' => $date, 'hour' => $hour, 'count' => 0];
        $deltas[$key]['count'] += $count;
    }
}
