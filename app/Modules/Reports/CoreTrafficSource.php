<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Models\Website;
use App\Modules\Contracts\ReportSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "core.traffic": what a digest needs from the Core, read from the consolidated statistics (never from collected
 * events): pageviews, custom events, sessions and goal conversions of the site over the period, in the site's time zone.
 */
final class CoreTrafficSource implements ReportSource
{
    public function label(): string
    {
        return 'Traffic';
    }

    public function sections(int $siteId, Carbon $from, Carbon $to): array
    {
        $site = Website::query()->whereKey($siteId)->whereNull('deleting_at')->first();
        if ($site === null) {
            return [];
        }
        // The period is [from, to) in UTC; the statistics are kept by local day, so the last instant belongs to the previous one.
        $first = $from->copy()->setTimezone($site->timezone)->toDateString();
        $last = $to->copy()->subSecond()->setTimezone($site->timezone)->toDateString();
        if ($last < $first) {
            return [];
        }
        $totals = DB::table('stats_daily')->where('website_id', $siteId)->whereBetween('stat_date', [$first, $last])
            ->selectRaw('COALESCE(SUM(pageviews), 0) AS pageviews, COALESCE(SUM(custom_events), 0) AS custom_events, COALESCE(SUM(sessions), 0) AS sessions')->first();
        $sections = [new ReportSection('Traffic', [
            ['label' => 'Pageviews', 'value' => (int) $totals->pageviews],
            ['label' => 'Sessions', 'value' => (int) $totals->sessions],
            ['label' => 'Custom events', 'value' => (int) $totals->custom_events],
        ])];
        $goals = DB::table('stats_goal_daily')->join('goals', 'goals.id', '=', 'stats_goal_daily.goal_id')
            ->where('stats_goal_daily.website_id', $siteId)->whereBetween('stats_goal_daily.stat_date', [$first, $last])
            ->groupBy('goals.id', 'goals.name')->orderBy('goals.id')
            ->selectRaw('goals.name AS name, SUM(stats_goal_daily.conversions) AS conversions')->get();
        $rows = [];
        foreach ($goals as $goal) {
            if ((int) $goal->conversions > 0) {
                $rows[] = ['label' => (string) $goal->name, 'value' => (int) $goal->conversions];
            }
        }
        if ($rows !== []) {
            $sections[] = new ReportSection('Goal conversions', $rows);
        }

        return $sections;
    }
}
