<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\DemoTraffic;
use App\Analytics\InstanceHealth;
use App\Analytics\Reporting\JourneyReports;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ResetDemoAnalytics extends Command
{
    protected $signature = 'perysee:demo-reset {--seed= : Optional seed for reproducible random traffic} {--only= : Reset the perysee.com demo site instead of the two classic ones}';

    protected $description = 'Reset only the known local demo sites with '.DemoTraffic::DAYS.' days of varied analytics and sample funnels';

    public function handle(Aggregator $aggregator, InstanceHealth $health): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo reset is restricted to local development and tests.');

            return self::FAILURE;
        }
        $seed = $this->option('seed') === null ? random_int(1, 2147483647) : filter_var($this->option('seed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($seed === false) {
            $this->error('Use an integer seed between 0 and 2147483647.');

            return self::FAILURE;
        }
        $only = $this->option('only');
        if ($only !== null && $only !== 'perysee.com') {
            $this->error('Use --only=perysee.com or omit the option.');

            return self::FAILURE;
        }
        $classicIds = ['c5d09ee1-e154-4625-b97c-6c8f8b27f102', 'c5d09ee1-e154-4625-b97c-6c8f8b27f103'];
        $requiredIds = $only === null ? $classicIds : [DemoTraffic::PERYSEE_COM];
        $publicIds = $requiredIds;
        $lock = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired !== 1) {
            $this->error('Aggregation or maintenance is running. Retry after it finishes.');

            return self::FAILURE;
        }
        try {
            $manifests = DB::transaction(function () use ($publicIds, $requiredIds, $aggregator, $health, $seed): array {
                $sites = Website::whereIn('public_id', $publicIds)->orderBy('id')->lockForUpdate()->get();
                if ($sites->whereIn('public_id', $requiredIds)->count() !== count($requiredIds) || $sites->contains(fn (Website $site): bool => ! $site->is_demo || $site->deleting_at !== null)) {
                    throw new RuntimeException('The known demo sites must exist, be marked demo, and not be pending deletion. Run LocalDemoSeeder first.');
                }
                $manifests = [];
                foreach ($sites as $site) {
                    foreach (['events', 'stats_daily', 'stats_hourly', 'stats_dimension_daily', 'measured_sessions', 'site_measurement_periods', 'stats_segment_daily', 'stats_segment_hourly', 'segment_events', 'segment_sessions', 'journey_report_sessions', 'journey_reports'] as $table) {
                        DB::table($table)->where('website_id', $site->id)->delete();
                    }
                    $generated = app(DemoTraffic::class)->generate($site, $seed);
                    $rows = $generated['rows'];
                    $expected = $generated['manifest'];
                    $start = now($site->timezone)->startOfDay()->subDays(DemoTraffic::DAYS - 1);
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table('events')->insert($chunk);
                    }
                    DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => $start->copy()->utc(), 'mode' => $site->tracking_mode]);
                    $site->forceFill(['first_collected_at' => $start->copy()->utc()])->save();
                    $health->put('demo_manifest:'.$site->id, $expected);
                    $manifests[$site->id] = $expected;
                }
                $aggregator->run(500, 300, $sites->modelKeys());
                if (DB::table('events')->whereIn('website_id', $sites->modelKeys())->whereNull('processed_at')->exists()) {
                    throw new RuntimeException('Demo aggregation did not finish. The reset was rolled back.');
                }

                foreach ($sites as $site) {
                    if ($site->tracking_mode === 'sessions') {
                        $this->prepareReports($site, $health);
                    }
                }
                $jobs = app(JourneyReports::class);
                $deadline = microtime(true) + 120;
                while (microtime(true) < $deadline && $jobs->tick(500, $sites->modelKeys())) {
                }
                if (DB::table('journey_reports')->whereIn('website_id', $sites->modelKeys())->where('status', '!=', 'ready')->exists()) {
                    throw new RuntimeException('Demo session-flow calculations did not finish. The reset was rolled back.');
                }

                return $manifests;
            });
            $this->info('Demo seed: '.$seed);
            foreach ($manifests as $id => $manifest) {
                $this->info('Demo '.$id.': '.$manifest['pageviews'].' pageviews, '.$manifest['custom_events'].' custom events.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    private function prepareReports(Website $site, InstanceHealth $health): void
    {
        $presets = $site->public_id === DemoTraffic::PERYSEE_COM ? $this->peryseePresets() : $this->classicPresets();
        $ids = $health->get('demo_funnels:'.$site->id, []);
        foreach ($presets as $name => $steps) {
            if (! isset($ids[$name]) || ! DB::table('funnels')->where('website_id', $site->id)->where('id', $ids[$name])->exists()) {
                $ids[$name] = DB::table('funnels')->insertGetId(['website_id' => $site->id, 'name' => $name, 'current_version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                DB::table('funnel_versions')->insert(['website_id' => $site->id, 'funnel_id' => $ids[$name], 'version' => 1, 'steps' => json_encode($steps, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            }
        }
        $health->put('demo_funnels:'.$site->id, $ids);
        foreach (['today', '7d', '30d'] as $preset) {
            $period = app(Reports::class)->period(Request::create('/', 'GET', ['period' => $preset]), $site);
            $payload = ['from' => $period['start']->toDateTimeString(), 'to' => $period['end']->toDateTimeString(), 'filters' => []];
            foreach ($ids as $id) {
                $version = DB::table('funnel_versions')->where('funnel_id', $id)->where('version', DB::table('funnels')->where('id', $id)->value('current_version'))->firstOrFail();
                app(JourneyReports::class)->request($site, ['kind' => 'funnel', 'funnel_id' => $id, 'version' => $version->version, 'steps' => json_decode($version->steps, true, flags: JSON_THROW_ON_ERROR), ...$payload]);
            }
            app(JourneyReports::class)->request($site, ['kind' => 'journey', 'start' => ['type' => 'path', 'value' => '/'], ...$payload]);
        }
    }

    /** @return array<string, array<int, array{type: string, value: string, label: string}>> */
    private function classicPresets(): array
    {
        return [
            'Product signup' => [['type' => 'path', 'value' => '/', 'label' => 'Landing page'], ['type' => 'path', 'value' => '/pricing', 'label' => 'Pricing'], ['type' => 'event', 'value' => 'signup', 'label' => 'Account created']],
            'Guide download' => [['type' => 'path', 'value' => '/articles/analytics-basics', 'label' => 'Read article'], ['type' => 'path', 'value' => '/resources/analytics-guide', 'label' => 'Guide page'], ['type' => 'event', 'value' => 'download', 'label' => 'Download']],
            'Contact request' => [['type' => 'path', 'value' => '/', 'label' => 'Landing page'], ['type' => 'path', 'value' => '/contact', 'label' => 'Contact page'], ['type' => 'event', 'value' => 'contact_submit', 'label' => 'Message sent']],
        ];
    }

    /** @return array<string, array<int, array{type: string, value: string, label: string}>> */
    private function peryseePresets(): array
    {
        return [
            'Discover to download' => [['type' => 'path', 'value' => '/', 'label' => 'Home'], ['type' => 'path', 'value' => '/product', 'label' => 'Product'], ['type' => 'event', 'value' => 'download_core', 'label' => 'Core downloaded']],
            'Compare to install' => [['type' => 'path', 'value' => '/compare', 'label' => 'Comparisons'], ['type' => 'path', 'value' => '/docs/getting-started/shared-hosting', 'label' => 'Install guide'], ['type' => 'event', 'value' => 'copy_snippet', 'label' => 'Snippet copied']],
            'Partner enquiry' => [['type' => 'path', 'value' => '/partners', 'label' => 'Partners'], ['type' => 'path', 'value' => '/pricing', 'label' => 'Editions'], ['type' => 'event', 'value' => 'contact_submit', 'label' => 'Enquiry sent']],
        ];
    }
}
