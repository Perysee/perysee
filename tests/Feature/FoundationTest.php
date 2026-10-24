<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_an_uninstalled_instance_redirects_to_the_installer(): void
    {
        $this->get('/')->assertRedirect('/install');
    }

    public function test_health_is_available_without_a_database_connection(): void
    {
        config(['database.default' => 'mariadb', 'database.connections.mariadb.host' => 'unavailable.invalid']);

        $this->get('/up')->assertOk();
    }

    public function test_an_uninstalled_collector_does_not_acknowledge_events(): void
    {
        $this->postJson('/api/collect', ['v' => 1, 'site' => 'example', 'type' => 'pageview', 'path' => '/'])
            ->assertStatus(503);
    }
}
