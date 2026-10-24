<?php

namespace Tests\Feature;

use App\Installation\EnvironmentFile;
use App\Installation\InstallationState;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstallationStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/perysee-install-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['perysee.installation_path' => $this->directory.'/private', 'perysee.environment_path' => $this->directory.'/.env']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_bootstrap_creates_a_stable_key_and_private_secret_without_a_database(): void
    {
        $state = app(InstallationState::class);
        $state->bootstrap();
        $secret = file_get_contents($state->secretPath());
        $key = app(EnvironmentFile::class)->values()['APP_KEY'];
        $state->bootstrap();
        $this->assertSame($secret, file_get_contents($state->secretPath()));
        $this->assertSame($key, app(EnvironmentFile::class)->values()['APP_KEY']);
        $this->assertTrue($state->acceptsSecret(trim($secret)));
        $this->assertFalse($state->acceptsSecret('incorrect'));
        $this->assertSame(0600, fileperms($state->secretPath()) & 0777);
    }

    public function test_completion_permanently_blocks_the_secret_even_after_bootstrap(): void
    {
        $state = app(InstallationState::class);
        $state->bootstrap();
        $secret = trim(file_get_contents($state->secretPath()));
        $state->complete();
        $state->bootstrap();
        $this->assertTrue($state->isLocked());
        $this->assertFileDoesNotExist($state->secretPath());
        $this->assertFalse($state->acceptsSecret($secret));
    }

    public function test_environment_updates_round_trip_special_characters_and_preserve_the_key(): void
    {
        $env = app(EnvironmentFile::class);
        $env->write(['APP_KEY' => 'existing', 'DB_PASSWORD' => 'old']);
        $password = 'space # " $dollar \\ slash';
        $env->write(['DB_PASSWORD' => $password]);
        $this->assertSame($password, $env->values()['DB_PASSWORD']);
        $this->assertSame('existing', $env->values()['APP_KEY']);
        $this->assertSame(0600, fileperms($this->directory.'/.env') & 0777);
    }

    public function test_concurrent_installation_is_rejected(): void
    {
        $state = app(InstallationState::class);
        $state->bootstrap();
        $handle = fopen($this->directory.'/private/operation.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->expectException(ValidationException::class);
            $state->exclusive(fn () => $this->fail('A second installation must not run.'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
