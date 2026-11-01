<?php

namespace Tests\Feature;

use App\Modules\ModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_are_stored_per_module_and_read_back_with_their_type(): void
    {
        $settings = app(ModuleSettings::class);

        $settings->set('white-label', 'name', 'Acme');
        $settings->set('white-label', 'options', ['dark' => true, 'size' => 3]);
        $settings->set('white-label', 'enabled', false);

        $this->assertSame('Acme', $settings->get('white-label', 'name'));
        $this->assertSame(['dark' => true, 'size' => 3], $settings->get('white-label', 'options'));
        $this->assertFalse($settings->get('white-label', 'enabled', true));
    }

    public function test_missing_values_return_the_default_and_modules_cannot_read_each_other(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->set('one', 'secret', 'x');

        $this->assertNull($settings->get('one', 'absent'));
        $this->assertSame('fallback', $settings->get('one', 'absent', 'fallback'));
        $this->assertNull($settings->get('two', 'secret'));
        $this->assertSame([], $settings->all('two'));
    }

    public function test_setting_a_value_again_replaces_it_and_forget_removes_it(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->set('one', 'name', 'A');
        $settings->set('one', 'name', 'B');

        $this->assertSame('B', $settings->get('one', 'name'));
        $this->assertDatabaseCount('module_settings', 1);

        $settings->forget('one', 'name');
        $this->assertNull($settings->get('one', 'name'));
        $this->assertSame([], $settings->all('one'));
    }

    public function test_reads_reflect_writes_made_in_the_same_request(): void
    {
        $settings = app(ModuleSettings::class);
        $this->assertSame([], $settings->all('one'));

        $settings->set('one', 'a', 1);

        $this->assertSame(['a' => 1], $settings->all('one'));
    }

    public function test_reads_before_the_table_exists_return_defaults_instead_of_failing(): void
    {
        Schema::drop('module_settings');

        $this->assertSame('x', app(ModuleSettings::class)->get('one', 'a', 'x'));
    }
}
