<?php

namespace App\Analytics;

use App\Analytics\Ingestion\Device;
use App\Analytics\Ingestion\Sources;
use App\Models\Website;
use Random\Engine\Mt19937;
use Random\Randomizer;

class DemoTraffic
{
    public const DAYS = 90;

    /** @return array{rows: array, manifest: array} */
    public function generate(Website $site, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed ^ crc32($site->public_id)));
        $choose = fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $now = now($site->timezone);
        $start = $now->copy()->startOfDay()->subDays(self::DAYS - 1);
        $rows = [];
        $profiles = [
            ['Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/605.1', 30],
            ['Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36', 24],
            ['Mozilla/5.0 (Macintosh) Version/18.0 Safari/605.1', 12],
            ['Mozilla/5.0 (Linux; Android 14; SM-S921B) Chrome/140.0 Mobile SamsungBrowser/28.0', 9],
            ['Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36 Edg/140.0', 7],
            ['Mozilla/5.0 (X11) Firefox/140.0', 6],
            ['Mozilla/5.0 (Linux; Android 14; Pixel 9) Chrome/140.0 Mobile Safari/537.36', 4],
            ['Mozilla/5.0 (Linux; Android 14; Redmi Note) Firefox/140.0 Mobile', 3],
            ['Mozilla/5.0 (iPad) Version/18.0 Mobile Safari/605.1', 3],
            ['', 2],
        ];
        $sources = ['Direct' => 25, 'google.com' => 29, 'bing.com' => 4, 'newsletter' => 9, 'github.com' => 5, 'chatgpt.com' => 9, 'claude.ai' => 4, 'perplexity.ai' => 4, 'gemini.google.com' => 3, 'copilot.microsoft.com' => 2, 'grok.com' => 1, 'linkedin.com' => 5, 'x.com' => 3, 'producthunt.com' => 2];
        $hours = [0, 1, 2, 5, 6, 7, 8, 8, 9, 9, 10, 10, 10, 11, 11, 12, 12, 13, 14, 14, 15, 15, 16, 16, 17, 18, 19, 19, 20, 21, 22, 23];
        $last = self::DAYS - 1;
        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $start->copy()->addDays($day);
            $weekend = $date->isWeekend() ? 0.62 : 1;
            $campaign = in_array($last - $day, [7, 8, 19, 20, 38, 39, 61, 62], true) ? 1.6 : 1;
            $count = (int) round((35 + $random->getInt(0, 26)) * $weekend * $campaign * (0.75 + $day / 120));
            $elapsed = $day === $last ? max(0, (int) $date->diffInSeconds($now)) : 86399;
            if ($day === $last) {
                $count = max(2, (int) round($count * min(1, $elapsed / 86400 + 0.08)));
            }
            $live = $day === $last ? $random->getInt(6, 10) : 0;
            for ($visit = 0; $visit < $count + $live; $visit++) {
                $second = $choose($hours) * 3600 + $random->getInt(0, 3599);
                if ($visit >= $count) {
                    $second = $elapsed - $random->getInt(20, 840);
                }
                if ($second > $elapsed || $second < 0) {
                    $second = $random->getInt(0, $elapsed);
                }
                $time = $date->copy()->addSeconds($second);
                $source = $this->weighted($random, $sources);
                $profile = $this->weighted($random, array_column($profiles, 1, 0));
                $client = app(Device::class)->details($profile);
                $country = $this->weighted($random, ['FR' => 46, 'US' => 16, 'DE' => 7, 'GB' => 6, 'CA' => 5, 'BE' => 5, 'CH' => 3, 'ES' => 3, 'JP' => 2, 'NL' => 2, 'IT' => 3, 'BR' => 2, 'IN' => 2, 'AU' => 1, 'Unknown' => 5]);
                $utm = match ($source) {
                    'newsletter' => ['newsletter', 'email', $choose(['product-update', 'analytics-guide', 'autumn-launch'])],
                    'linkedin.com' => $random->getInt(1, 100) < 50 ? ['linkedin', 'social', $choose(['product-update', 'autumn-launch'])] : null,
                    'x.com' => $random->getInt(1, 100) < 40 ? ['twitter', 'social', 'autumn-launch'] : null,
                    'producthunt.com' => ['producthunt', 'referral', 'launch-day'],
                    'google.com' => $random->getInt(1, 100) <= 15 ? ['google', 'cpc', $choose(['brand-search', 'privacy-analytics'])] : null,
                    default => null,
                };
                $base = ['website_id' => $site->id, 'consent_given' => true, 'tracking_mode' => $site->tracking_mode,
                    'session_key' => $site->tracking_mode === 'sessions' ? hash('sha256', $site->public_id.':'.$seed.':'.$day.':'.$visit) : null,
                    'dimensions_available' => true, 'referrer_status' => $source === 'Direct' ? 'absent' : 'valid', 'referrer_host' => str_contains($source, '.') ? $source : null,
                    ...$client, 'country_code' => $country === 'Unknown' ? null : $country, 'geo_available' => true,
                    'utm_source' => $utm[0] ?? null, 'utm_medium' => $utm[1] ?? null, 'utm_campaign' => $utm[2] ?? null];
                $base['source_value'] = app(Sources::class)->resolve($base['utm_source'], $base['referrer_host'], $base['referrer_status']);
                $intent = $random->getInt(1, 100);
                if ($intent <= 46) {
                    $actions = ['/'];
                    if ($random->getInt(1, 100) <= 25) {
                        $actions[] = 'video_play';
                    }
                    if ($random->getInt(1, 100) <= 76) {
                        $actions[] = '/features';
                    }
                    if ($random->getInt(1, 100) <= 66) {
                        $actions[] = '/pricing';
                        if ($random->getInt(1, 100) <= 30) {
                            $actions[] = 'pricing_toggle';
                        }
                        if ($random->getInt(1, 100) <= 47) {
                            $actions[] = '/signup';
                            if ($random->getInt(1, 100) <= 68) {
                                $actions[] = 'signup';
                                $actions[] = '/welcome';
                            }
                        }
                    }
                } elseif ($intent <= 73) {
                    $actions = ['/articles/analytics-basics'];
                    if ($random->getInt(1, 100) <= 71) {
                        $actions[] = '/resources/analytics-guide';
                        if ($random->getInt(1, 100) <= 43) {
                            $actions[] = 'download';
                        }
                    }
                    if ($random->getInt(1, 100) <= 35) {
                        $actions[] = '/articles/'.$choose(['privacy-first', 'campaign-attribution', 'reading-your-data', 'self-hosted-analytics', 'measuring-conversions']);
                    }
                    if ($random->getInt(1, 100) <= 18) {
                        $actions[] = 'newsletter_subscribe';
                    }
                } elseif ($intent <= 88) {
                    $actions = ['/'];
                    if ($random->getInt(1, 100) <= 58) {
                        $actions[] = '/about';
                        if ($random->getInt(1, 100) <= 20) {
                            $actions[] = 'outbound_click';
                        }
                    }
                    if ($random->getInt(1, 100) <= 67) {
                        $actions[] = '/contact';
                        if ($random->getInt(1, 100) <= 39) {
                            $actions[] = 'contact_submit';
                        }
                    }
                } else {
                    $actions = ['/docs/installation'];
                    for ($i = 0, $length = $random->getInt(0, 4); $i < $length; $i++) {
                        $actions[] = $choose(['/docs/events', '/docs/consent', '/docs/reports', '/docs/installation', '/pricing', '/changelog']);
                    }
                    if ($random->getInt(1, 100) <= 35) {
                        $actions[] = 'copy_snippet';
                    }
                }
                if ($random->getInt(1, 100) <= 12) {
                    array_splice($actions, 1, 0, ['/blog', '/']);
                }
                $path = '/';
                foreach ($actions as $index => $action) {
                    if ($index > 0) {
                        $time->addSeconds($random->getInt(12, 240));
                    }
                    if ($time->gt($now)) {
                        break;
                    }
                    $pageview = str_starts_with($action, '/');
                    if ($pageview) {
                        $path = $action;
                    }
                    $rows[] = $base + ['received_at' => $time->copy()->utc()->format('Y-m-d H:i:s.v'), 'event_type' => $pageview ? 1 : 2, 'path' => $path, 'event_name' => $pageview ? null : $action];
                }
            }
        }
        $manifest = ['version' => 5, 'seed' => $seed, 'from' => $start->toDateString(), 'to' => $now->toDateString(), 'pageviews' => 0, 'custom_events' => 0, 'sessions' => null, 'countries' => [], 'sources' => [], 'devices' => [], 'brands' => [], 'browsers' => [], 'pages' => [], 'events' => []];
        $sessions = [];
        foreach ($rows as $row) {
            if ($row['event_type'] === 2) {
                $manifest['custom_events']++;
                $manifest['events'][$row['event_name']] = ($manifest['events'][$row['event_name']] ?? 0) + 1;

                continue;
            }
            $manifest['pageviews']++;
            if ($row['session_key'] !== null) {
                $sessions[$row['session_key']] = true;
            }
            foreach (['countries' => $row['country_code'] ?? 'Unknown', 'sources' => $row['source_value'], 'devices' => $row['device_type'], 'brands' => $row['device_brand'], 'browsers' => $row['browser_name'], 'pages' => $row['path']] as $group => $value) {
                $manifest[$group][$value] = ($manifest[$group][$value] ?? 0) + 1;
            }
        }
        $manifest['sessions'] = $site->tracking_mode === 'sessions' ? count($sessions) : null;

        return ['rows' => $rows, 'manifest' => $manifest];
    }

    private function weighted(Randomizer $random, array $weights): string
    {
        $draw = $random->getInt(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $draw -= $weight;
            if ($draw <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }
}
