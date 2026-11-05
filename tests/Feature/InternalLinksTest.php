<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Support\Ui;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nuxt UI treats any link that carries a protocol as external and renders a plain anchor, so a click reloads the
 * whole document instead of making an Inertia visit. Every link the server sends for the interface to follow must
 * therefore be a path.
 */
class InternalLinksTest extends TestCase
{
    use RefreshDatabase;

    /** Strings shown or used as-is, never followed as navigation. */
    private const ALLOWED = [
        '/^assets\./',
        '/^modules\.(scripts|styles)\.\d+$/',
        '/^snippet$/',
        '/^settings\./',
        '/^docsUrl$/',
        '/\.favicon_url$/',
        '/^navigation\.\d+\.favicon_url$/',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(base_path('modules/white-label'))) {
            $this->markTestSkipped('The official modules live in Perysee/perysee-modules; they are not checked out here.');
        }
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.modules_path' => base_path('modules')]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        foreach (['white-label', 'annotations', 'example'] as $module) {
            app(ModuleRegistry::class)->enable($module);
        }
        app(ModuleLoader::class)->load();
    }

    /**
     * @return list<string> the path of every string that starts with the application address
     */
    private function absoluteLinks(mixed $value, string $path = ''): array
    {
        $root = rtrim(url('/'), '/');
        if (is_string($value)) {
            return $value === $root || str_starts_with($value, $root.'/') ? [$path] : [];
        }
        if (! is_array($value)) {
            return [];
        }
        $found = [];
        foreach ($value as $key => $item) {
            array_push($found, ...$this->absoluteLinks($item, $path === '' ? (string) $key : $path.'.'.$key));
        }

        return $found;
    }

    public function test_no_screen_sends_an_absolute_link_for_the_interface_to_follow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $site = Website::factory()->create();
        $site->members()->attach($admin, ['role' => 'owner']);
        $this->actingAs($admin);
        $offenders = [];

        foreach (['/dashboard', '/sites', "/sites/{$site->id}", "/sites/{$site->id}/pages", "/sites/{$site->id}/funnels", "/sites/{$site->id}/journeys", "/sites/{$site->id}/tracking", '/sites/create', '/settings', '/health', '/settings/modules', '/settings/modules/white-label', '/settings/modules/annotations', "/modules/annotations/sites/{$site->id}"] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $props = $response->viewData('page')['props'];
            foreach ($this->absoluteLinks($props) as $path) {
                if (! collect(self::ALLOWED)->contains(fn (string $pattern): bool => (bool) preg_match($pattern, $path))) {
                    $offenders["{$url} → {$path}"] = true;
                }
            }
        }

        $this->assertSame([], array_keys($offenders), "These links are absolute, so Nuxt UI renders them as external anchors and every click reloads the page:\n".implode("\n", array_keys($offenders)));
    }

    public function test_a_screen_cannot_reuse_a_property_the_application_shares_with_every_page(): void
    {
        foreach (['links', 'modules', 'navigation', 'auth'] as $shared) {
            try {
                Ui::render('sites.index', [$shared => []]);
                $this->fail("A screen sent \"{$shared}\" without an error.");
            } catch (\LogicException $exception) {
                $this->assertStringContainsString("\"{$shared}\"", $exception->getMessage());
            }
        }
    }
}
