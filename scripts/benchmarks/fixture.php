<?php

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/tests/bootstrap-mariadb.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === 'prepare') {
    Artisan::call('migrate', ['--force' => true]);
    $public = (string) Str::uuid();
    DB::table('websites')->insert(['public_id' => $public, 'name' => 'Private benchmark fixture', 'domain' => 'benchmark.invalid', 'allowed_domains' => '["benchmark.invalid"]', 'timezone' => 'UTC', 'tracking_mode' => 'pageviews', 'consent_required' => true, 'created_at' => now(), 'updated_at' => now()]);
    echo json_encode(['site' => $public, 'php' => PHP_VERSION, 'database' => DB::selectOne('SELECT VERSION() AS version')->version, 'os' => php_uname(), 'cpu' => getenv('BENCHMARK_CPU') ?: 'Record physical CPU separately', 'server_workers' => 4], JSON_THROW_ON_ERROR);
} else {
    Artisan::call('migrate', ['--force' => true]);
    $site = DB::table('websites')->where('public_id', $argv[2] ?? '')->where('domain', 'benchmark.invalid')->firstOrFail();
    $size = DB::selectOne("SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'events'")->bytes;
    $count = DB::table('events')->where('website_id', $site->id)->count();
    $start = hrtime(true);
    $processed = app(Aggregator::class)->run(500, 300);
    $elapsed = (hrtime(true) - $start) / 1e6;
    $reportStart = hrtime(true);
    $model = Website::findOrFail($site->id);
    $reports = app(Reports::class);
    $period = $reports->period(Request::create('/', 'GET', ['period' => '30d']), $model);
    $samples = [];
    for ($sample = 0; $sample < 100; $sample++) {
        $reportStart = hrtime(true);
        $overview = $reports->overview($model, $period);
        $reports->series($model, $period);
        $reports->dimensions($model, $period);
        $samples[] = (hrtime(true) - $reportStart) / 1e6;
    }
    sort($samples);
    echo json_encode(['confirmed_events' => $count, 'aggregated_events' => $processed, 'pageviews' => $overview['totals']['pageviews'], 'aggregation_ms' => $elapsed, 'report_samples' => 100, 'report_p95_ms' => $samples[94], 'events_table_estimated_bytes' => $size, 'peak_cli_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
    if (($argv[1] ?? '') === 'cleanup') {
        foreach (['events', 'stats_daily', 'stats_hourly', 'stats_dimension_daily', 'measured_sessions', 'site_members', 'site_measurement_periods'] as $table) {
            DB::table($table)->where('website_id', $site->id)->delete();
        }
        DB::table('websites')->where('id', $site->id)->delete();
        DB::table('analytics_state')->where('name', 'aggregate_site:'.$site->id)->delete();
    }
}
