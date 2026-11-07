<?php

namespace App\Analytics\Reporting;

use App\Analytics\Aggregation\Goals;
use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GoalReports
{
    public const BREAKDOWNS = ['source', 'campaign', 'country', 'device'];

    /** A goal of this site, or null: a goal of another site is never found. */
    public function definition(Website $site, int $id): ?object
    {
        return DB::table('goals')->where('website_id', $site->id)->where('id', $id)->first();
    }

    /** One version of a goal (its current one by default), or null when it does not exist. */
    public function version(object $definition, ?int $version = null): ?object
    {
        return DB::table('goal_versions')->where('goal_id', $definition->id)->where('version', $version ?? $definition->current_version)->first();
    }

    public function query(Website $site, int $goal, int $version, array $period): Builder
    {
        $query = DB::table('stats_goal_daily')->where('website_id', $site->id)->where('goal_id', $goal)->where('version', $version)->whereBetween('stat_date', [$period['from'], $period['to']]);

        return app(Filters::class)->apply($query, $period['filters'] ?? []);
    }

    /**
     * Conversions, rate and breakdowns of one goal version. A site that does not measure sessions has no
     * conversion to count, only the hits on the goal: the rate and the conversions are then null.
     *
     * @return array<string, mixed>
     */
    public function report(Website $site, int $goal, int $version, array $period): array
    {
        $sessionsMode = $site->tracking_mode === 'sessions';
        $coverage = $this->coverage($site, $goal, $version, $period);
        $segments = app(SegmentReports::class);
        $rateAvailable = $sessionsMode && $coverage['missing_sessions'] === 0;
        $totals = (clone $this->query($site, $goal, $version, $period))->selectRaw('SUM(conversions) AS conversions, SUM(hits) AS hits')->first();
        $sessions = (int) (clone $segments->query($site, $period))->sum('sessions');
        $conversions = (int) $totals->conversions;

        $goalDaily = $this->query($site, $goal, $version, $period)->select('stat_date')->selectRaw('SUM(conversions) AS conversions, SUM(hits) AS hits')->groupBy('stat_date')->get()->keyBy('stat_date');
        $sessionDaily = $segments->query($site, $period)->select('stat_date')->selectRaw('SUM(sessions) AS sessions')->groupBy('stat_date')->get()->keyBy('stat_date');
        $series = [];
        for ($day = Carbon::parse($period['from']); $day->toDateString() <= $period['to']; $day->addDay()) {
            $date = $day->toDateString();
            $daily = (int) ($goalDaily->get($date)->conversions ?? 0);
            $daySessions = (int) ($sessionDaily->get($date)->sessions ?? 0);
            $series[] = ['date' => $date, 'label' => $date, 'conversions' => $sessionsMode ? $daily : null, 'hits' => (int) ($goalDaily->get($date)->hits ?? 0), 'sessions' => $rateAvailable ? $daySessions : null, 'rate' => $rateAvailable ? $this->rate($daily, $daySessions) : null];
        }
        $breakdown = [];
        foreach (self::BREAKDOWNS as $dimension) {
            $breakdown[$dimension] = $this->breakdown($site, $goal, $version, $period, $dimension, $sessionsMode, $rateAvailable);
        }

        return [
            'mode' => $sessionsMode ? 'sessions' : 'pageviews',
            'totals' => ['conversions' => $sessionsMode ? $conversions : null, 'hits' => (int) $totals->hits, 'sessions' => $rateAvailable ? $sessions : null, 'rate' => $rateAvailable ? $this->rate($conversions, $sessions) : null],
            'series' => $series, 'breakdown' => $breakdown, 'coverage' => $coverage, 'filters' => $period['filters'] ?? [],
        ];
    }

    /**
     * @return list<array{value: string, label: string, conversions: int|null, hits: int, sessions: int|null, rate: float|null}>
     */
    private function breakdown(Website $site, int $goal, int $version, array $period, string $dimension, bool $sessionsMode, bool $rateAvailable): array
    {
        $metric = $sessionsMode ? 'conversions' : 'hits';
        $rows = $this->query($site, $goal, $version, $period)->whereNotNull($dimension)->select($dimension.' AS value')->selectRaw('SUM(conversions) AS conversions, SUM(hits) AS hits')->groupBy($dimension)
            ->havingRaw('SUM('.$metric.') > 0')->orderByDesc(DB::raw('SUM('.$metric.')'))->orderBy($dimension)->limit(100)->get();
        $sessions = $rateAvailable && $rows->isNotEmpty()
            ? app(SegmentReports::class)->query($site, $period)->whereIn($dimension, $rows->pluck('value'))->select($dimension.' AS value')->selectRaw('SUM(sessions) AS sessions')->groupBy($dimension)->pluck('sessions', 'value')
            : collect();

        return $rows->map(function ($row) use ($dimension, $sessionsMode, $rateAvailable, $sessions): array {
            $count = $rateAvailable ? (int) ($sessions->get($row->value) ?? 0) : null;

            return ['value' => $row->value, 'label' => $dimension === 'campaign' ? $this->campaign($row->value) : $row->value, 'conversions' => $sessionsMode ? (int) $row->conversions : null, 'hits' => (int) $row->hits, 'sessions' => $count, 'rate' => $rateAvailable ? $this->rate((int) $row->conversions, $count) : null];
        })->all();
    }

    private function rate(int $conversions, ?int $sessions): ?float
    {
        return $sessions ? round(100 * $conversions / $sessions, 2) : null;
    }

    private function campaign(string $json): string
    {
        $data = json_decode($json, true);
        $parts = array_filter([$data['campaign'] ?? null, ($data['source'] ?? null) ? trim(($data['source'] ?? '').' / '.($data['medium'] ?? ''), ' /') : null]);

        return $parts ? implode(' — ', $parts) : $json;
    }

    /**
     * What the figures cover: the session denominator, the goal backfill, and the retention of the raw events a
     * goal defined after the fact is counted from.
     *
     * @return array{status: string, missing_sessions: int, not_reconstructible: bool, backfilling: bool, message: string}
     */
    public function coverage(Website $site, int $goal, int $version, array $period): array
    {
        $segment = app(SegmentReports::class)->coverage($site, $period);
        $retention = (int) app(InstanceHealth::class)->get('raw_retention_days', config('perysee.raw_retention_days', 90));
        $created = DB::table('goal_versions')->where('goal_id', $goal)->where('version', $version)->value('created_at');
        $beyond = $created !== null && Carbon::parse($created, 'UTC')->gt($period['start']) && $period['start']->lt(now('UTC')->subDays($retention));
        $backfilling = app(Goals::class)->backfilling($goal, $version);
        $missing = (int) $segment['missing_sessions'];
        $status = $backfilling || $segment['status'] === 'rebuilding' ? 'rebuilding' : ($beyond || $missing > 0 || $segment['status'] === 'partial' ? 'partial' : 'available');
        $message = match (true) {
            $backfilling => 'This goal is still being counted over past events. Figures are incomplete.',
            $beyond => 'This goal was defined after the raw events of part of this period were deleted, so earlier conversions cannot be reconstructed.',
            $missing > 0 => 'Some sessions of this period are not measured yet, so the conversion rate is unavailable.',
            default => 'Conversions cover measured sessions of the retained period.',
        };

        return ['status' => $status, 'missing_sessions' => $missing, 'not_reconstructible' => $beyond, 'backfilling' => $backfilling, 'message' => $message, 'raw_retention_days' => $retention];
    }
}
