<?php

namespace Tests\Feature;

use App\Updates\UpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DockerInstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_instance_running_in_docker_is_updated_by_rebuilding_the_image_not_in_place(): void
    {
        config(['perysee.install_mode' => 'docker']);
        $state = app(UpdateStatus::class)->current()['state'];

        $this->artisan('perysee:update', ['--yes' => true])
            ->expectsOutputToContain('--mode docker --upgrade')
            ->assertFailed();

        $this->assertSame($state, app(UpdateStatus::class)->current()['state']);
    }

    public function test_the_scheduler_leaves_an_update_request_alone_in_docker(): void
    {
        config(['perysee.install_mode' => 'docker']);
        $status = app(UpdateStatus::class);
        $status->request('v9.9.9', 'auto', 1);

        $this->artisan('perysee:update', ['--requested' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame('v9.9.9', $status->requested()['tag']);
    }

    public function test_a_standard_installation_is_unchanged(): void
    {
        $this->assertSame('standard', config('perysee.install_mode'));
    }
}
