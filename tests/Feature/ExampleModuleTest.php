<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.modules_path' => base_path('modules')]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app(ModuleRegistry::class)->enable('example');
        app(ModuleLoader::class)->load();
    }

    public function test_it_loads_installs_nothing_in_the_database_and_adds_no_navigation(): void
    {
        $this->assertSame([], app(ModuleLoader::class)->failures());
        $this->assertTrue(app(ModuleRegistry::class)->all()['example']->installed);

        $this->actingAs(User::factory()->create())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.navigation', ['workspace' => [], 'admin' => [], 'site' => []])
            ->where('modules.data.example.greeting', 'Hello from the Example module.'));
    }

    public function test_an_administrator_changes_the_greeting_everyone_sees_on_the_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/modules/example/greeting', ['greeting' => 'Welcome aboard'])
            ->assertRedirect(route('modules.settings', 'example'))
            ->assertSessionHas('status');

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.example.greeting', 'Welcome aboard')
            ->where('modules.data.example.save', null));
        $this->actingAs($admin)->get('/settings/modules/example')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.example.save', '/modules/example/greeting'));
    }

    public function test_only_administrators_can_change_it_and_the_text_is_validated(): void
    {
        $this->actingAs(User::factory()->create())->post('/modules/example/greeting', ['greeting' => 'Hi'])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/modules/example/greeting', ['greeting' => ''])->assertSessionHasErrors('greeting');
        $this->post('/modules/example/greeting', ['greeting' => str_repeat('x', 81)])->assertSessionHasErrors('greeting');
        $this->post('/modules/example/greeting', ['greeting' => '<b>bold</b>'])->assertSessionHasErrors('greeting');
        $this->assertDatabaseCount('module_settings', 0);
    }

    public function test_it_is_removed_cleanly_by_disabling_it(): void
    {
        app(ModuleRegistry::class)->disable('example');

        $this->assertFalse(app(ModuleRegistry::class)->all()['example']->enabled);
    }
}
