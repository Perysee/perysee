<?php

namespace Tests\Feature;

use App\Analytics\Reporting\Reports;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DimensionReportOutputTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        $site = Website::factory()->create();
        $rows = [
            ['2026-10-24', 'source', 'ChatGPT', 5], ['2026-10-24', 'source', 'chatgpt', 3], ['2026-10-24', 'source', 'chat.openai.com', 2],
            ['2026-10-24', 'source', 'Claude', 4], ['2026-10-24', 'source', 'Direct', 6], ['2026-10-24', 'source', 'news.example', 1],
            ['2026-10-25', 'source', 'chatgpt.com', 2], ['2026-10-25', 'source', 'google', 7], ['2026-10-25', 'source', 'CLAUDE.AI', 1],
            ['2026-10-24', 'path', '/a', 3], ['2026-10-25', 'path', '/a', 2], ['2026-10-25', 'path', '/b', 4],
            ['2026-10-24', 'country', 'FR', 5], ['2026-10-25', 'country', 'FR', 1], ['2026-10-25', 'country', 'US', 3],
            ['2026-10-25', 'event', 'signup', 2],
        ];
        foreach ($rows as [$date, $type, $value, $count]) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'dimension_type' => $type, 'dimension_hash' => hash('sha256', $type.$value), 'dimension_value' => $value, 'metric_count' => $count]);
        }

        return $site;
    }

    public function test_the_dimension_reports_keep_their_output(): void
    {
        $site = $this->site();
        $period = ['from' => '2026-10-23', 'to' => '2026-10-26', 'filters' => [], 'source_group' => 'all', 'provider' => null];
        $cases = [
            'source' => [$period, 'source', null],
            'ai' => [['source_group' => 'ai'] + $period, 'source', null],
            'provider' => [['provider' => 'claude'] + $period, 'source', null],
            'mixed case value' => [$period, 'source', 'ChatGPT'],
            'path' => [$period, 'path', null],
            'path value' => [$period, 'path', '/a'],
            'country' => [$period, 'country', null],
            'event' => [$period, 'event', null],
            'none' => [$period, 'device', null],
        ];
        $out = [];
        foreach ($cases as $name => [$p, $type, $value]) {
            $out[$name] = app(Reports::class)->dimensionReport($site, $p, $type, $value);
        }

        // The fixture is the output of the report before it was reduced to fewer queries.
        $this->assertEquals(json_decode((string) file_get_contents(base_path('tests/Fixtures/dimension-reports.json')), true), json_decode((string) json_encode($out), true));
    }
}
