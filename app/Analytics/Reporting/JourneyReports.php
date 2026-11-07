<?php

namespace App\Analytics\Reporting;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class JourneyReports
{
    public function request(Website $site, array $payload): object
    {
        $snapshot = (int) DB::table('events')->where('website_id', $site->id)->max('id');
        $payload['at'] = now('UTC')->toDateTimeString();
        $keyData = $payload;
        unset($keyData['at']);
        $window = $site->is_demo ? 'demo-snapshot' : intdiv(now()->timestamp, 300);
        $key = hash('sha256', json_encode([$site->id, $keyData, $snapshot, $window], JSON_THROW_ON_ERROR));
        DB::table('journey_reports')->insertOrIgnore(['website_id' => $site->id, 'cache_key' => $key, 'snapshot_id' => $snapshot, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return DB::table('journey_reports')->where('cache_key', $key)->first();
    }

    /**
     * The newest finished funnel report of a site for this funnel version, period and filters, or null. Read only:
     * it never asks for a calculation, so a caller cannot make the application write or scan events.
     *
     * @param  array<string, mixed>  $period  a result of Reports::period()
     */
    public function latestReady(Website $site, int $funnel, int $version, array $period): ?object
    {
        $from = $period['start']->toDateTimeString();
        $to = $period['end']->toDateTimeString();
        $rows = DB::table('journey_reports')->where('website_id', $site->id)->where('status', 'ready')->where('payload', 'like', '%"kind":"funnel"%')->orderByDesc('id')->limit(200)->get(['id', 'payload', 'result', 'updated_at']);
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (is_array($payload) && ($payload['funnel_id'] ?? null) === $funnel && ($payload['version'] ?? null) === $version && ($payload['from'] ?? null) === $from && ($payload['to'] ?? null) === $to && ($payload['filters'] ?? []) == $period['filters']) {
                return $row;
            }
        }

        return null;
    }

    public function publicStatus(object $report): array
    {
        return ['id' => $report->id, 'status' => $report->status, 'scanned_events' => $report->scanned, 'excluded_sessions' => $report->excluded, 'updated_at' => $report->updated_at, 'error' => $report->error, 'data' => $report->result ? json_decode($report->result, true, flags: JSON_THROW_ON_ERROR) : null];
    }

    public function tick(int $batch = 500, ?array $siteIds = null): bool
    {
        $waiting = DB::table('journey_reports')->when($siteIds !== null, fn ($query) => $query->whereIn('website_id', $siteIds))->whereIn('status', ['scanning', 'reducing']);
        if (! $waiting->exists()) {
            return false;
        }

        return DB::transaction(function () use ($batch, $siteIds): bool {
            $report = DB::table('journey_reports')->when($siteIds !== null, fn ($query) => $query->whereIn('website_id', $siteIds))->whereIn('status', ['scanning', 'reducing'])->orderBy('id')->lockForUpdate()->first();
            if (! $report) {
                return false;
            }
            try {
                $site = Website::find($report->website_id);
                if (! $site || $site->deleting_at) {
                    DB::table('journey_reports')->where('id', $report->id)->update(['status' => 'error', 'error' => 'Site is unavailable.']);

                    return true;
                }
                $payload = json_decode($report->payload, true, flags: JSON_THROW_ON_ERROR);
                $updates = ['updated_at' => now('UTC')];
                if ($report->status === 'scanning') {
                    $start = Carbon::parse($payload['from'], 'UTC')->subDay();
                    $end = Carbon::parse($payload['to'], 'UTC')->addDay();
                    $events = DB::table('events')->where('website_id', $site->id)->whereNotNull('session_key')->where('id', '>', $report->cursor)->where('id', '<=', $report->snapshot_id)->where('received_at', '>=', $start)->where('received_at', '<', $end)->orderBy('id')->limit($batch)->get();
                    foreach ($events->groupBy('session_key') as $key => $rows) {
                        $identity = ['report_id' => $report->id, 'session_key' => $key];
                        $existing = DB::table('journey_report_sessions')->where($identity)->first();
                        $items = $existing ? json_decode($existing->events, true, flags: JSON_THROW_ON_ERROR) : [];
                        $all = array_merge($items, $rows->map(fn ($row) => (array) $row)->all());
                        DB::table('journey_report_sessions')->updateOrInsert($identity, ['website_id' => $site->id, 'events' => json_encode(array_slice($all, 0, 10000), JSON_THROW_ON_ERROR), 'truncated' => ($existing->truncated ?? false) || count($all) > 10000]);
                    }
                    $updates['cursor'] = $events->isEmpty() ? 0 : $events->last()->id;
                    $updates['scanned'] = $report->scanned + $events->count();
                    if ($events->count() < $batch) {
                        $updates['status'] = 'reducing';
                        $updates['cursor'] = 0;
                    }
                } else {
                    $state = $report->state ? json_decode($report->state, true, flags: JSON_THROW_ON_ERROR) : [];
                    $rows = DB::table('journey_report_sessions')->where('report_id', $report->id)->where('id', '>', $report->cursor)->orderBy('id')->limit(min(5, $batch))->get();
                    $excluded = (int) $report->excluded;
                    foreach ($rows as $row) {
                        $events = json_decode($row->events, true, flags: JSON_THROW_ON_ERROR);
                        $first = min(array_column($events, 'received_at'));
                        $known = DB::table('measured_sessions')->where('website_id', $site->id)->where('session_key', $row->session_key)->value('first_seen_at');
                        if ($row->truncated || ($known && Carbon::parse($known)->lt(Carbon::parse($first)))) {
                            $excluded++;
                        } else {
                            app(FunnelEngine::class)->consume($state, $events, $payload);
                        }
                    }
                    $updates['excluded'] = $excluded;
                    $updates['state'] = json_encode($state, JSON_THROW_ON_ERROR);
                    $updates['cursor'] = $rows->isEmpty() ? $report->cursor : $rows->last()->id;
                    if ($rows->count() < min(5, $batch)) {
                        $result = app(FunnelEngine::class)->finish($state, $payload);
                        $days = (int) app(InstanceHealth::class)->get('raw_retention_days', config('perysee.raw_retention_days', 90));
                        $partial = Carbon::parse($payload['from'], 'UTC')->subDay()->lt(now('UTC')->subDays($days));
                        $result['coverage'] = ['status' => $partial || $excluded ? 'partial' : 'available', 'excluded_sessions' => $excluded, 'raw_retention_days' => $days, 'message' => 'Based on retained session events. Truncated sessions are excluded; past purged sequences cannot be reconstructed.'];
                        if ($partial || $excluded) {
                            $result['conversion_rate'] = null;
                            foreach ($result['steps'] as &$step) {
                                $step['rate'] = null;
                            }
                            unset($step);
                        }
                        $updates['result'] = json_encode($result, JSON_THROW_ON_ERROR);
                        $updates['status'] = 'ready';
                        $updates['state'] = null;
                    }
                }
                DB::table('journey_reports')->where('id', $report->id)->update($updates);
                if (($updates['status'] ?? '') === 'ready') {
                    DB::table('journey_report_sessions')->where('report_id', $report->id)->delete();
                }
            } catch (Throwable $exception) {
                DB::table('journey_reports')->where('id', $report->id)->update(['status' => 'error', 'error' => str_starts_with($exception->getMessage(), 'Too many distinct paths.') ? $exception->getMessage() : 'Calculation failed ('.class_basename($exception).'). Retry with a new report.', 'updated_at' => now('UTC')]);
            }

            return true;
        }, 3);
    }
}
