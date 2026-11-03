<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleExtensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleHealthCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_a_module_card_reaches_the_health_page_of_administrators(): void
    {
        app(ModuleExtensions::class)->healthCard('demo', fn (): array => ['title' => 'Demo service', 'status' => 'ok', 'items' => [['label' => 'Latency', 'value' => '2 ms'], ['label' => 'Keys', 'value' => 42]]]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/health')->assertInertia(fn (Assert $page) => $page
            ->where('moduleCards', [['module' => 'demo', 'title' => 'Demo service', 'status' => 'ok', 'items' => [['label' => 'Latency', 'value' => '2 ms'], ['label' => 'Keys', 'value' => '42']]]]));
    }

    public function test_a_failing_or_invalid_card_is_left_out_without_breaking_the_page(): void
    {
        $extensions = app(ModuleExtensions::class);
        $extensions->healthCard('broken', fn (): array => throw new \RuntimeException('unreachable'));
        $extensions->healthCard('untitled', fn (): array => ['status' => 'ok', 'items' => []]);
        $extensions->healthCard('odd', fn (): array => ['title' => str_repeat('x', 200), 'status' => 'unknown', 'items' => [['label' => "A\nB", 'value' => '<b>1</b>'], ['label' => 5]]]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/health')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('moduleCards', 1)
            ->where('moduleCards.0.module', 'odd')
            ->where('moduleCards.0.status', 'attention')
            ->where('moduleCards.0.title', str_repeat('x', 60))
            ->where('moduleCards.0.items', [['label' => 'A B', 'value' => '<b>1</b>']]));
    }

    public function test_members_who_are_not_administrators_cannot_see_cards(): void
    {
        app(ModuleExtensions::class)->healthCard('demo', fn (): array => ['title' => 'Demo', 'status' => 'ok', 'items' => []]);

        $this->actingAs(User::factory()->create())->get('/health')->assertForbidden();
    }
}
