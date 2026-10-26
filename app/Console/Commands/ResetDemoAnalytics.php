<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Ingestion\Device;
use App\Analytics\Ingestion\Sources;
use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ResetDemoAnalytics extends Command
{
    protected $signature = 'perysee:demo-reset';

    protected $description = 'Reset only the two known local demo sites with 30 days of deterministic analytics';

    public function handle(Aggregator $aggregator, InstanceHealth $health): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo reset is restricted to local development and tests.');

            return self::FAILURE;
        }
        $publicIds = ['c5d09ee1-e154-4625-b97c-6c8f8b27f102', 'c5d09ee1-e154-4625-b97c-6c8f8b27f103'];
        $lock = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired !== 1) {
            $this->error('Aggregation or maintenance is running. Retry after it finishes.');

            return self::FAILURE;
        }
        try {
            $manifests = DB::transaction(function () use ($publicIds, $aggregator, $health): array {
                $sites = Website::whereIn('public_id', $publicIds)->orderBy('id')->lockForUpdate()->get();
                if ($sites->count() !== 2 || $sites->contains(fn (Website $site): bool => ! $site->is_demo || $site->deleting_at !== null)) {
                    throw new RuntimeException('The two known demo sites must exist, be marked demo, and not be pending deletion. Run LocalDemoSeeder first.');
                }
                $manifests = [];
                foreach ($sites as $site) {
                    foreach (['events', 'stats_daily', 'stats_hourly', 'stats_dimension_daily', 'measured_sessions', 'site_measurement_periods'] as $table) {
                        DB::table($table)->where('website_id', $site->id)->delete();
                    }
                    $start = now($site->timezone)->startOfDay()->subDays(29);
                    $rows = [];
                    $expected = ['version' => 3, 'from' => $start->toDateString(), 'to' => now($site->timezone)->toDateString(), 'pageviews' => 0, 'custom_events' => 0, 'sessions' => $site->tracking_mode === 'sessions' ? 0 : null, 'countries' => [], 'sources' => [], 'devices' => [], 'brands' => [], 'browsers' => [], 'pages' => [], 'events' => []];
                    $sessions = [];
                    $sourceValues = ['Direct', 'Direct', 'Direct', 'google.com', 'google.com', 'google.com', 'bing.com', 'newsletter', 'github.com', 'chatgpt.com', 'chatgpt.com', 'chatgpt.com', 'claude.ai', 'claude.ai', 'perplexity.ai', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'grok.com', 'linkedin.com'];
                    $paths = ['/', '/pricing', '/features', '/docs', '/blog', '/contact', '/about'];
                    $devices = ['desktop', 'mobile', 'mobile', 'desktop', 'tablet', 'unknown'];
                    $countries = ['FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'FR', 'US', 'US', 'US', 'US', 'US', 'US', 'DE', 'DE', 'DE', 'GB', 'GB', 'CA', 'CA', 'BE', 'CH', 'ES', 'JP', null, null];
                    $add = function (Carbon $received, int $index, ?string $forcedSession = null) use (&$rows, &$expected, &$sessions, $site, $sourceValues, $paths, $devices, $countries): void {
                        $source = $sourceValues[$index % count($sourceValues)];
                        $device = $devices[$index % count($devices)];
                        $profiles = [
                            'desktop' => ['Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36', 'Mozilla/5.0 (Macintosh) Version/18.0 Safari/605.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36 Edg/140.0', 'Mozilla/5.0 (X11) Firefox/140.0'],
                            'mobile' => ['Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/605.1', 'Mozilla/5.0 (Linux; Android 14; SM-S921B) Chrome/140.0 Mobile Safari/537.36 SamsungBrowser/28.0', 'Mozilla/5.0 (Linux; Android 10; K) Chrome/140.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 14; Pixel 9) Chrome/140.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 14; Redmi Note) Firefox/140.0 Mobile'],
                            'tablet' => ['Mozilla/5.0 (iPad) Version/18.0 Mobile Safari/605.1', 'Mozilla/5.0 (Linux; Android 14; SM-T870) SamsungBrowser/28.0 Chrome/140.0 Safari/537.36'],
                            'unknown' => [''],
                        ];
                        $profile = $profiles[$device][(int) floor($index / 6) % count($profiles[$device])];
                        $client = app(Device::class)->details($profile);
                        $country = $countries[$index % count($countries)];
                        $path = $index % 11 === 0 ? '/articles/story-'.chr(97 + $index % 26).chr(97 + (int) floor($index / 26) % 26) : $paths[$index % count($paths)];
                        $session = $site->tracking_mode === 'sessions' ? hash('sha256', $site->public_id.':'.($forcedSession ?? $received->toDateString().':'.(int) floor($index / 4))) : null;
                        if ($session !== null) {
                            $sessions[$session] = true;
                        }
                        $row = ['website_id' => $site->id, 'received_at' => $received->copy()->utc()->format('Y-m-d H:i:s.v'), 'event_type' => 1, 'path' => $path, 'consent_given' => true, 'tracking_mode' => $site->tracking_mode, 'session_key' => $session, 'dimensions_available' => true, 'source_value' => $source, 'referrer_status' => $source === 'Direct' ? 'absent' : 'valid', 'referrer_host' => str_contains($source, '.') ? $source : null, ...$client, 'country_code' => $country, 'geo_available' => true, 'utm_source' => $index % 5 === 0 ? 'newsletter' : null, 'utm_medium' => $index % 5 === 0 ? 'email' : null, 'utm_campaign' => $index % 5 === 0 ? 'autumn-launch' : null, 'event_name' => null];
                        // Use the same UTM-first resolver as real collection.
                        $row['source_value'] = app(Sources::class)->resolve($row['utm_source'], $row['referrer_host'], $row['referrer_status']);
                        if ($source === 'Direct' && ! $row['utm_source']) {
                            $row['source_value'] = 'Direct';
                        }
                        $source = $row['source_value'];
                        $rows[] = $row;
                        $expected['pageviews']++;
                        foreach (['countries' => $country ?? 'Unknown', 'sources' => $source, 'devices' => $device, 'brands' => $client['device_brand'], 'browsers' => $client['browser_name'], 'pages' => $path] as $type => $key) {
                            $expected[$type][$key] = ($expected[$type][$key] ?? 0) + 1;
                        }
                        if ($index % 9 === 0) {
                            $name = ['signup', 'download', 'contact_click'][$index % 3 === 0 ? (int) ($index / 9) % 3 : 0];
                            $rows[] = array_merge($row, ['event_type' => 2, 'event_name' => $name]);
                            $expected['custom_events']++;
                            $expected['events'][$name] = ($expected['events'][$name] ?? 0) + 1;
                        }
                    };
                    for ($day = 0; $day < 30; $day++) {
                        $date = $start->copy()->addDays($day);
                        $count = 48 + ($day * 13 % 80);
                        $seconds = $day === 29 ? max(1, $date->diffInSeconds(now($site->timezone))) : 86300;
                        for ($index = 0; $index < $count; $index++) {
                            $received = $date->copy()->addSeconds((int) ($seconds * ($index + 1) / ($count + 1)));
                            $add($received, $day * 100 + $index);
                        }
                    }
                    $add(now($site->timezone)->startOfDay()->subMinute(), 3301, 'cross-midnight');
                    $add(now($site->timezone)->startOfDay(), 3302, 'cross-midnight');
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table('events')->insert($chunk);
                    }
                    $expected['sessions'] = $site->tracking_mode === 'sessions' ? count($sessions) : null;
                    DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => $start->copy()->utc(), 'mode' => $site->tracking_mode]);
                    $site->forceFill(['first_collected_at' => $start->copy()->utc()])->save();
                    $health->put('demo_manifest:'.$site->id, $expected);
                    $manifests[$site->id] = $expected;
                }
                $aggregator->run(500, 300, $sites->modelKeys());
                if (DB::table('events')->whereIn('website_id', $sites->modelKeys())->whereNull('processed_at')->exists()) {
                    throw new RuntimeException('Demo aggregation did not finish. The reset was rolled back.');
                }

                return $manifests;
            });
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
}
