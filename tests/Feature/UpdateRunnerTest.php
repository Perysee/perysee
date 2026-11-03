<?php

namespace Tests\Feature;

use App\Updates\UpdateException;
use App\Updates\UpdateOptions;
use App\Updates\UpdateRunner;
use App\Updates\UpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Runs a whole update against a temporary copy of an installation: the real application tree is never touched.
 * The "artisan" of each version is a small script, so the second process the update starts is really run.
 */
class UpdateRunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $live;

    private string $archive;

    private const ARCHIVE_URL = 'https://downloads.test/perysee.zip';

    protected function setUp(): void
    {
        parent::setUp();
        $this->live = $this->installationDirectory.'/live';
        mkdir($this->live.'/storage/framework', 0755, true);
        mkdir($this->live.'/storage/app/private', 0700, true);
        $this->app->setBasePath($this->live);
        $this->app->useStoragePath($this->live.'/storage');
        config(['perysee.environment_path' => $this->live.'/.env']);
        file_put_contents($this->live.'/.env', 'APP_KEY=base64:keep');
        mkdir($this->live.'/app');
        file_put_contents($this->live.'/app/Marker.php', 'old');
        file_put_contents($this->live.'/artisan', $this->fakeArtisan('old', finalize: 0));
        $this->archive = $this->release('9.9.9', finalize: 0);
    }

    /** What `php artisan ...` does in the fixture: `up` and the finalize command reopen the site. */
    private function fakeArtisan(string $label, int $finalize): string
    {
        $down = var_export($this->live.'/storage/framework/down', true);

        return "<?php\n\$arguments = \$argv;\nif (in_array('perysee:update:finalize', \$arguments, true)) {\n    echo \"finalizing {$label}\\n\";\n    if ({$finalize} === 0) { @unlink({$down}); }\n    exit({$finalize});\n}\nif (in_array('up', \$arguments, true)) { @unlink({$down}); }\nexit(0);\n";
    }

    private function release(string $version, int $finalize): string
    {
        $path = $this->installationDirectory.'/perysee.zip';
        @unlink($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ([
            'artisan' => $this->fakeArtisan('new', $finalize), 'app/Marker.php' => 'new', 'config/perysee.php' => "<?php return ['version' => '{$version}'];",
            'public/index.php' => '<?php // front', 'public/build/manifest.json' => '{}', 'vendor/autoload.php' => '<?php',
            'vendor/composer/platform_check.php' => '<?php if (!(PHP_VERSION_ID >= 80300)) {}',
            'release.json' => json_encode(['version' => "v{$version}", 'repository' => 'Perysee/app']),
        ] as $name => $contents) {
            $zip->addFromString('perysee/'.$name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function publish(string $archive, ?string $sha256 = null): void
    {
        $sha256 ??= hash_file('sha256', $archive);
        Http::fake([
            'api.github.com/repos/Perysee/app/releases/*' => Http::response(['tag_name' => 'v9.9.9', 'draft' => false, 'published_at' => '2026-11-04T10:00:00Z', 'html_url' => 'https://github.com/Perysee/app/releases/tag/v9.9.9', 'assets' => [
                ['name' => 'perysee.zip', 'browser_download_url' => self::ARCHIVE_URL],
                ['name' => 'SHA256SUMS', 'browser_download_url' => 'https://downloads.test/SHA256SUMS'],
            ]]),
            'downloads.test/SHA256SUMS' => Http::response("{$sha256}  perysee.zip\n".str_repeat('0', 64)."  install.php\n"),
            'downloads.test/perysee.zip' => Http::response((string) file_get_contents($archive)),
        ]);
    }

    /**
     * @return list<string>
     */
    private function runUpdate(UpdateOptions $options): array
    {
        $lines = [];
        try {
            $this->app->make(UpdateRunner::class)->run($options, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
        } finally {
            $this->lines = $lines;
        }

        return $lines;
    }

    /** @var list<string> */
    private array $lines = [];

    public function test_an_update_downloads_verifies_backs_up_swaps_and_reopens_the_site(): void
    {
        $this->publish($this->archive);

        $lines = $this->runUpdate(new UpdateOptions(dump: 'php'));

        $this->assertSame('new', file_get_contents($this->live.'/app/Marker.php'));
        $this->assertSame('APP_KEY=base64:keep', file_get_contents($this->live.'/.env'));
        $this->assertFileDoesNotExist($this->live.'/storage/framework/down');
        $status = $this->app->make(UpdateStatus::class)->current();
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame('9.9.9', $status['to']);
        $this->assertFileExists($status['backup'].'/database.sql.gz');
        $this->assertSame('APP_KEY=base64:keep', file_get_contents($status['backup'].'/env'));
        $this->assertSame('old', file_get_contents($status['backup'].'/previous/app/Marker.php'));
        $this->assertContains('  finalizing new', $lines);
        $this->assertDirectoryDoesNotExist($this->live.'/storage/app/private/updates/work-'.substr(basename(glob($this->live.'/storage/app/private/updates/work-*')[0] ?? 'x'), 5));
    }

    public function test_a_failed_finalization_restores_the_previous_files_and_reopens_when_no_migration_ran(): void
    {
        $this->archive = $this->release('9.9.9', finalize: 1);
        $this->publish($this->archive);

        try {
            $this->runUpdate(new UpdateOptions(dump: 'php'));
            $this->fail('The update should have failed.');
        } catch (UpdateException $exception) {
            $this->assertStringContainsString('could not finish the update', $exception->getMessage());
            $this->assertStringContainsString('back online', $exception->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->live.'/app/Marker.php'));
        $this->assertSame('APP_KEY=base64:keep', file_get_contents($this->live.'/.env'));
        $this->assertFileDoesNotExist($this->live.'/storage/framework/down');
        $this->assertSame('failed', $this->app->make(UpdateStatus::class)->current()['state']);
    }

    public function test_a_tampered_archive_is_refused_before_any_change(): void
    {
        $this->publish($this->archive, str_repeat('a', 64));

        try {
            $this->runUpdate(new UpdateOptions);
            $this->fail('The update should have failed.');
        } catch (UpdateException $exception) {
            $this->assertStringContainsString('does not match its published SHA-256', $exception->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->live.'/app/Marker.php'));
        $this->assertFileDoesNotExist($this->live.'/storage/framework/down');
        $this->assertDirectoryDoesNotExist($this->live.'/storage/app/private/updates/backups');
        $this->assertSame('failed', $this->app->make(UpdateStatus::class)->current()['state']);
    }

    public function test_nothing_happens_when_the_installed_version_is_the_latest(): void
    {
        $this->publish($this->archive);
        config(['perysee.version' => '9.9.9']);

        $lines = $this->runUpdate(new UpdateOptions);

        $this->assertSame(['Already up to date (9.9.9).'], $lines);
        $this->assertSame('old', file_get_contents($this->live.'/app/Marker.php'));
    }

    public function test_a_downloaded_archive_can_be_installed_with_its_published_checksum_and_no_network(): void
    {
        Http::fake();

        $this->runUpdate(new UpdateOptions(archive: $this->archive, sha256: hash_file('sha256', $this->archive), dump: 'php'));

        $this->assertSame('new', file_get_contents($this->live.'/app/Marker.php'));
        Http::assertNothingSent();
    }

    public function test_an_archive_needs_its_checksum(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('--sha256');
        $this->runUpdate(new UpdateOptions(archive: $this->archive));
    }

    public function test_a_failing_backup_leaves_the_site_untouched_and_open(): void
    {
        $this->publish($this->archive);

        try {
            $this->runUpdate(new UpdateOptions(dump: 'external', dumpFile: $this->live.'/missing.sql'));
            $this->fail('The update should have failed.');
        } catch (UpdateException $exception) {
            $this->assertStringContainsString('does not exist or is empty', $exception->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->live.'/app/Marker.php'));
        $this->assertFileDoesNotExist($this->live.'/storage/framework/down');
    }

    public function test_a_second_update_cannot_start_while_one_is_running(): void
    {
        mkdir($this->live.'/storage/app/private/updates', 0700, true);
        $held = fopen($this->live.'/storage/app/private/updates/update.lock', 'c');
        flock($held, LOCK_EX);

        try {
            $this->runUpdate(new UpdateOptions);
            $this->fail('The update should have been refused.');
        } catch (UpdateException $exception) {
            $this->assertSame('Another update is running.', $exception->getMessage());
        } finally {
            fclose($held);
        }
    }
}
