<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventValueRejectionDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_a_refused_value_is_filed_once_as_an_invalid_payload_under_its_site(): void
    {
        $site = Website::factory()->create();
        $payload = ['v' => 1, 'site' => $site->public_id, 'type' => 'event', 'mode' => $site->tracking_mode ?: 'pageviews', 'host' => $site->domain, 'path' => '/thanks', 'consent' => true, 'event_name' => 'purchase', 'value' => -1];
        if ($site->tracking_mode === 'sessions') {
            $payload['session'] = str_repeat('a', 32);
        }

        $this->withHeaders(['Origin' => 'https://'.$site->domain])->postJson('/api/collect', $payload)->assertUnprocessable();

        $rows = DB::table('collection_diagnostics')->where('website_id', $site->id)->where('cause', 'invalid_payload')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->hits);
        $this->assertSame(0, DB::table('events')->count());
    }
}
