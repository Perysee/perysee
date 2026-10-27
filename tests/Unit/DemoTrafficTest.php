<?php

namespace Tests\Unit;

use App\Analytics\DemoTraffic;
use App\Models\Website;
use Tests\TestCase;

class DemoTrafficTest extends TestCase
{
    public function test_random_traffic_is_reproducible_per_seed_and_sessions_follow_coherent_paths(): void
    {
        $this->freezeTime();
        $site = new Website(['timezone' => 'Europe/Paris', 'tracking_mode' => 'sessions']);
        $site->forceFill(['id' => 1, 'public_id' => 'demo-fixture']);
        $generator = app(DemoTraffic::class);
        $first = $generator->generate($site, 42);
        $this->assertSame($first, $generator->generate($site, 42));
        $this->assertNotSame($first['manifest']['pageviews'], $generator->generate($site, 43)['manifest']['pageviews']);
        $this->assertGreaterThan(1000, $first['manifest']['pageviews']);
        $this->assertGreaterThan(0, $first['manifest']['events']['signup']);
        $this->assertGreaterThan(0, $first['manifest']['events']['download']);
        $this->assertLessThanOrEqual(now('UTC')->format('Y-m-d H:i:s.v'), max(array_column($first['rows'], 'received_at')));
        $sessions = collect($first['rows'])->groupBy('session_key');
        foreach ($sessions->take(20) as $events) {
            $this->assertCount(1, $events->pluck('device_type')->unique());
            $this->assertCount(1, $events->pluck('country_code')->unique());
        }
    }
}
