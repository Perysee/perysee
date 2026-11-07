<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function definition(array $override = []): array
    {
        return $override + ['name' => 'Signup', 'type' => 'event', 'value' => 'signup', 'threshold' => 1];
    }

    /**
     * @return array{0: Website, 1: User, 2: User}
     */
    private function members(): array
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $site->members()->attach($viewer, ['role' => 'viewer']);

        return [$site, $owner, $viewer];
    }

    public function test_owner_creates_an_event_goal_and_viewer_cannot(): void
    {
        [$site, $owner, $viewer] = $this->members();

        $this->actingAs($viewer)->post('/sites/'.$site->id.'/goals', $this->definition())->assertForbidden();
        $this->assertDatabaseCount('goals', 0);

        $this->actingAs($owner)->post('/sites/'.$site->id.'/goals', $this->definition())->assertRedirect();
        $goal = DB::table('goals')->first();
        $this->assertSame('Signup', $goal->name);
        $this->assertSame(1, (int) $goal->current_version);
        $version = DB::table('goal_versions')->first();
        $this->assertSame(['event', 'signup', 1], [$version->type, $version->value, (int) $version->threshold]);

        $this->actingAs($viewer)->get('/sites/'.$site->id.'/goals')->assertOk();
        $this->get('/sites/'.$site->id.'/goals/'.$goal->id)->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/sites/'.$site->id.'/goals')->assertForbidden();
    }

    public function test_editing_creates_a_version_and_archiving_keeps_history(): void
    {
        [$site, $owner] = $this->members();
        $this->actingAs($owner)->post('/sites/'.$site->id.'/goals', $this->definition());
        $id = DB::table('goals')->value('id');

        $this->put('/sites/'.$site->id.'/goals/'.$id, $this->definition(['type' => 'path', 'value' => '/thanks/?utm=x#top', 'threshold' => 3]))->assertRedirect();
        $this->assertDatabaseCount('goal_versions', 2);
        $this->assertSame(2, (int) DB::table('goals')->value('current_version'));
        $latest = DB::table('goal_versions')->where('version', 2)->first();
        $this->assertSame(['path', '/thanks/', 3], [$latest->type, $latest->value, (int) $latest->threshold]);

        $this->delete('/sites/'.$site->id.'/goals/'.$id)->assertRedirect();
        $this->assertNotNull(DB::table('goals')->value('archived_at'));
        $this->assertDatabaseCount('goal_versions', 2);
    }

    public function test_invalid_definitions_and_the_limit_of_five_active_goals_are_refused(): void
    {
        [$site, $owner] = $this->members();
        $this->actingAs($owner);
        $this->post('/sites/'.$site->id.'/goals', $this->definition(['value' => '1 bad name']))->assertSessionHasErrors('value');
        $this->post('/sites/'.$site->id.'/goals', $this->definition(['type' => 'prefix']))->assertSessionHasErrors('type');
        $this->post('/sites/'.$site->id.'/goals', $this->definition(['threshold' => 0]))->assertSessionHasErrors('threshold');
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/sites/'.$site->id.'/goals', $this->definition(['name' => 'Goal '.$i, 'value' => 'event_'.$i]))->assertSessionHasNoErrors();
        }
        $this->post('/sites/'.$site->id.'/goals', $this->definition(['name' => 'Sixth', 'value' => 'sixth']))->assertSessionHasErrors('name');
        $this->assertDatabaseCount('goals', 5);
        $this->delete('/sites/'.$site->id.'/goals/'.DB::table('goals')->value('id'));
        $this->post('/sites/'.$site->id.'/goals', $this->definition(['name' => 'Sixth', 'value' => 'sixth']))->assertSessionHasNoErrors();
    }

    public function test_goals_of_another_site_are_not_reachable(): void
    {
        [$site, $owner] = $this->members();
        $other = Website::factory()->create();
        $other->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->post('/sites/'.$other->id.'/goals', $this->definition());
        $id = DB::table('goals')->value('id');
        $this->get('/sites/'.$site->id.'/goals/'.$id)->assertNotFound();
        $this->put('/sites/'.$site->id.'/goals/'.$id, $this->definition())->assertNotFound();
    }
}
