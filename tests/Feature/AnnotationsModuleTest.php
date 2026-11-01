<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnotationsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private User $outsider;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.modules_path' => base_path('modules')]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app(ModuleRegistry::class)->enable('annotations');
        app(ModuleLoader::class)->load();
        [$this->owner, $this->viewer, $this->outsider] = User::factory()->count(3)->create()->all();
        $this->site = Website::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
        $this->site->members()->attach($this->viewer, ['role' => 'viewer']);
    }

    private function note(string $body = 'Launched the new pricing page', string $date = '2026-10-20'): array
    {
        return ['body' => $body, 'noted_on' => $date];
    }

    public function test_installing_the_module_creates_its_table_and_loads_without_errors(): void
    {
        $this->assertTrue(Schema::hasTable('annotations_notes'));
        $this->assertSame([], app(ModuleLoader::class)->failures());
    }

    public function test_an_owner_adds_a_note_and_sees_it_on_the_site_page(): void
    {
        $this->actingAs($this->owner)->post('/modules/annotations/sites/'.$this->site->id.'/notes', $this->note())
            ->assertRedirect('/modules/annotations/sites/'.$this->site->id);

        $this->get('/modules/annotations/sites/'.$this->site->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('screen', 'module:annotations')
            ->where('website.id', $this->site->id)
            ->where('canWrite', true)
            ->where('noteLinks.store', '/modules/annotations/sites/'.$this->site->id.'/notes')
            ->where('links.dashboard', '/dashboard', 'the menu links of the application must survive on a module page')
            ->where('notes.0', fn ($note) => $note['body'] === 'Launched the new pricing page' && $note['noted_on'] === '2026-10-20' && $note['author'] === $this->owner->name));
    }

    public function test_a_viewer_reads_notes_but_cannot_write_them(): void
    {
        DB::table('annotations_notes')->insert(['website_id' => $this->site->id, 'noted_on' => '2026-10-01', 'body' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->viewer);

        $this->get('/modules/annotations/sites/'.$this->site->id)->assertInertia(fn (Assert $page) => $page->where('canWrite', false)->has('notes', 1));
        $this->post('/modules/annotations/sites/'.$this->site->id.'/notes', $this->note())->assertForbidden();
        $this->delete('/modules/annotations/sites/'.$this->site->id.'/notes/1')->assertForbidden();
        $this->assertDatabaseCount('annotations_notes', 1);
    }

    public function test_someone_outside_the_site_cannot_see_or_touch_its_notes(): void
    {
        $this->actingAs($this->outsider);

        $this->get('/modules/annotations/sites/'.$this->site->id)->assertForbidden();
        $this->post('/modules/annotations/sites/'.$this->site->id.'/notes', $this->note())->assertForbidden();
        $this->assertDatabaseCount('annotations_notes', 0);
    }

    public function test_a_note_must_be_short_and_dated(): void
    {
        $this->actingAs($this->owner);
        $url = '/modules/annotations/sites/'.$this->site->id.'/notes';

        $this->post($url, ['body' => '', 'noted_on' => '2026-10-20'])->assertSessionHasErrors('body');
        $this->post($url, ['body' => str_repeat('x', 281), 'noted_on' => '2026-10-20'])->assertSessionHasErrors('body');
        $this->post($url, ['body' => 'ok', 'noted_on' => 'yesterday'])->assertSessionHasErrors('noted_on');
        $this->post($url, ['body' => 'ok', 'noted_on' => '2026-02-31'])->assertSessionHasErrors('noted_on');
        $this->assertDatabaseCount('annotations_notes', 0);
    }

    public function test_an_owner_deletes_a_note_of_their_site_only(): void
    {
        $other = Website::factory()->create();
        $other->members()->attach($this->owner, ['role' => 'owner']);
        $mine = DB::table('annotations_notes')->insertGetId(['website_id' => $this->site->id, 'noted_on' => '2026-10-01', 'body' => 'Mine', 'created_at' => now(), 'updated_at' => now()]);
        $theirs = DB::table('annotations_notes')->insertGetId(['website_id' => $other->id, 'noted_on' => '2026-10-01', 'body' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner);

        $this->delete('/modules/annotations/sites/'.$this->site->id.'/notes/'.$theirs)->assertNotFound();
        $this->assertDatabaseHas('annotations_notes', ['id' => $theirs]);

        $this->delete('/modules/annotations/sites/'.$this->site->id.'/notes/'.$mine)->assertRedirect('/modules/annotations/sites/'.$this->site->id);
        $this->assertDatabaseMissing('annotations_notes', ['id' => $mine]);
    }

    public function test_the_module_follows_its_site_selection(): void
    {
        $other = Website::factory()->create();
        $other->members()->attach($this->owner, ['role' => 'owner']);
        app(ModuleScope::class)->limitTo('annotations', [$this->site->id]);
        $this->actingAs($this->owner);

        $this->get('/modules/annotations/sites/'.$this->site->id)->assertOk();
        $this->get('/modules/annotations/sites/'.$other->id)->assertNotFound();
        $this->post('/modules/annotations/sites/'.$other->id.'/notes', $this->note())->assertNotFound();
        $this->get('/sites/'.$this->site->id)->assertInertia(fn (Assert $page) => $page->where('modules.navigation.site.0.label', 'Annotations'));
        $this->get('/sites/'.$other->id)->assertInertia(fn (Assert $page) => $page->where('modules.navigation.site', []));
    }

    public function test_site_pages_receive_the_latest_notes_for_the_block_under_the_reports(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            DB::table('annotations_notes')->insert(['website_id' => $this->site->id, 'noted_on' => $day, 'body' => 'Note '.$day, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($this->viewer)->get('/sites/'.$this->site->id)->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.annotations.recent', fn ($recent) => count($recent) === 3 && $recent[0]['body'] === 'Note 2026-10-04')
            ->where('modules.data.annotations.page', '/modules/annotations/sites/'.$this->site->id));
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->missing('modules.data.annotations.recent'));
    }

    public function test_the_retention_setting_is_for_administrators_and_validated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($this->owner)->post('/modules/annotations/settings', ['retention_days' => 400])->assertForbidden();
        $this->actingAs($admin);
        $this->post('/modules/annotations/settings', ['retention_days' => 10])->assertSessionHasErrors('retention_days');
        $this->post('/modules/annotations/settings', ['retention_days' => 'forever'])->assertSessionHasErrors('retention_days');
        $this->post('/modules/annotations/settings', ['retention_days' => 400])->assertRedirect(route('modules.settings', 'annotations'));

        $this->get('/settings/modules/annotations')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.annotations.settings.retention_days', 400));
        $this->actingAs($this->owner)->get('/sites')->assertInertia(fn (Assert $page) => $page->where('modules.data.annotations.settings', null));
    }

    public function test_the_prune_command_deletes_notes_older_than_the_retention_and_runs_daily(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/modules/annotations/settings', ['retention_days' => 30]);
        foreach ([now()->subDays(40)->toDateString() => 'old', now()->subDays(10)->toDateString() => 'recent'] as $day => $body) {
            DB::table('annotations_notes')->insert(['website_id' => $this->site->id, 'noted_on' => $day, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->artisan('annotations:prune')->expectsOutputToContain('Deleted 1')->assertSuccessful();

        $this->assertSame(['recent'], DB::table('annotations_notes')->pluck('body')->all());
        $this->assertContains('annotations prune', collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->all());
    }

    public function test_deleting_a_website_removes_its_notes_and_uninstalling_drops_the_table(): void
    {
        DB::table('annotations_notes')->insert(['website_id' => $this->site->id, 'noted_on' => '2026-10-01', 'body' => 'Gone', 'created_at' => now(), 'updated_at' => now()]);

        $this->site->delete();
        $this->assertDatabaseCount('annotations_notes', 0);

        app(ModuleRegistry::class)->disable('annotations');
        app(ModuleRegistry::class)->uninstall('annotations');
        $this->assertFalse(Schema::hasTable('annotations_notes'));
    }

    public function test_it_ships_a_settings_page_and_a_block_under_the_site_reports(): void
    {
        $this->actingAs($this->owner)->get('/sites/'.$this->site->id)->assertInertia(fn (Assert $page) => $page
            ->where('modules.scripts', [url('/modules/annotations/assets/module.js').'?v=1.0.0-'.substr(md5_file(base_path('modules/annotations/dist/module.js')), 0, 8)])
            ->where('modules.active', ['annotations']));
    }
}
