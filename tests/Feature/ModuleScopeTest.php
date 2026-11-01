<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Modules\ModuleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleScopeTest extends TestCase
{
    use RefreshDatabase;

    private function scope(): ModuleScope
    {
        return app(ModuleScope::class);
    }

    public function test_a_module_is_active_for_the_whole_instance_by_default(): void
    {
        $site = Website::factory()->create();

        $this->assertTrue($this->scope()->coversAllSites('alpha'));
        $this->assertTrue($this->scope()->isActiveFor('alpha', $site));
        $this->assertTrue($this->scope()->isActiveFor('alpha', null));
    }

    public function test_limiting_a_module_to_some_sites_deactivates_it_elsewhere(): void
    {
        [$one, $two, $three] = Website::factory()->count(3)->create()->all();

        $this->scope()->limitTo('alpha', [$one->id, $three->id]);

        $this->assertFalse($this->scope()->coversAllSites('alpha'));
        $this->assertTrue($this->scope()->isActiveFor('alpha', $one));
        $this->assertFalse($this->scope()->isActiveFor('alpha', $two));
        $this->assertTrue($this->scope()->isActiveFor('alpha', $three));
        $this->assertEqualsCanonicalizing([$one->id, $three->id], $this->scope()->siteIds('alpha'));
    }

    public function test_pages_outside_any_site_stay_available_to_a_limited_module(): void
    {
        $this->scope()->limitTo('alpha', [Website::factory()->create()->id]);

        $this->assertTrue($this->scope()->isActiveFor('alpha', null));
    }

    public function test_scopes_are_independent_between_modules(): void
    {
        $site = Website::factory()->create();

        $this->scope()->limitTo('alpha', []);

        $this->assertFalse($this->scope()->isActiveFor('alpha', $site));
        $this->assertTrue($this->scope()->isActiveFor('beta', $site));
    }

    public function test_an_empty_selection_means_no_site_not_every_site(): void
    {
        $site = Website::factory()->create();

        $this->scope()->limitTo('alpha', []);

        $this->assertFalse($this->scope()->coversAllSites('alpha'));
        $this->assertFalse($this->scope()->isActiveFor('alpha', $site));
    }

    public function test_going_back_to_all_sites_forgets_the_selection(): void
    {
        $site = Website::factory()->create();
        $this->scope()->limitTo('alpha', [$site->id]);

        $this->scope()->allowAllSites('alpha');

        $this->assertTrue($this->scope()->coversAllSites('alpha'));
        $this->assertSame([], $this->scope()->siteIds('alpha'));
        $this->assertDatabaseCount('module_sites', 0);
    }

    public function test_unknown_sites_are_ignored_and_a_site_removed_leaves_the_selection(): void
    {
        $kept = Website::factory()->create();
        $removed = Website::factory()->create();

        $this->scope()->limitTo('alpha', [$kept->id, $removed->id, 999999]);
        $this->assertEqualsCanonicalizing([$kept->id, $removed->id], $this->scope()->siteIds('alpha'));

        $removed->delete();
        app()->forgetInstance(ModuleScope::class);
        $this->assertSame([$kept->id], $this->scope()->siteIds('alpha'));
    }

    public function test_a_site_being_deleted_is_never_active(): void
    {
        $site = Website::factory()->create();
        DB::table('websites')->where('id', $site->id)->update(['deleting_at' => now()]);

        $this->scope()->limitTo('alpha', [$site->id]);

        $this->assertSame([], $this->scope()->siteIds('alpha'));
    }

    public function test_the_whole_state_of_a_request_is_read_with_few_queries(): void
    {
        Website::factory()->count(3)->create();
        $this->scope()->limitTo('alpha', [1, 2]);
        $this->scope()->limitTo('beta', [3]);
        app()->forgetInstance(ModuleScope::class);

        DB::enableQueryLog();
        $scope = $this->scope();
        $scope->isActiveFor('alpha', Website::find(1));
        $scope->isActiveFor('beta', Website::find(3));
        $scope->isActiveFor('alpha', Website::find(2));
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'module'))->count();

        $this->assertLessThanOrEqual(2, $queries);
    }
}
