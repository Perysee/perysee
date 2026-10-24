<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $installationDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installationDirectory = sys_get_temp_dir().'/perysee-test-'.bin2hex(random_bytes(8));
        mkdir($this->installationDirectory, 0700);
        config([
            'perysee.installation_path' => $this->installationDirectory.'/private',
            'perysee.environment_path' => $this->installationDirectory.'/.env',
            'app.locale' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->installationDirectory);
        parent::tearDown();
    }
}
