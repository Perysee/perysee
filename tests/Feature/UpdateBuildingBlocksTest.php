<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Updates\ArchiveExtractor;
use App\Updates\Backup;
use App\Updates\CodeSwapper;
use App\Updates\Dump\NativeDump;
use App\Updates\Dump\PhpDump;
use App\Updates\PackageVerifier;
use App\Updates\UpdateException;
use App\Updates\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class UpdateBuildingBlocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->useStoragePath($this->installationDirectory.'/storage');
        mkdir(storage_path('app/private'), 0700, true);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function archive(array $files): string
    {
        $path = $this->installationDirectory.'/release-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function release(string $version = 'v0.2.0'): array
    {
        return [
            'perysee/artisan' => '<?php // artisan', 'perysee/config/perysee.php' => "<?php return ['version' => '0.2.0'];",
            'perysee/public/index.php' => '<?php // front', 'perysee/public/build/manifest.json' => '{}',
            'perysee/vendor/autoload.php' => '<?php', 'perysee/vendor/composer/platform_check.php' => '<?php if (!(PHP_VERSION_ID >= 80300)) {}',
            'perysee/release.json' => json_encode(['version' => $version, 'repository' => 'Perysee/perysee']),
        ];
    }

    public function test_versions_with_or_without_the_tag_prefix_are_the_same_version(): void
    {
        $this->assertSame('0.2.0', Version::normalize('v0.2.0'));
        $this->assertSame('v0.2.0', Version::tag('0.2.0'));
        $this->assertTrue(Version::isNewer('v0.10.0', '0.9.9'));
        $this->assertFalse(Version::isNewer('0.2.0', 'v0.2.0'));
        $this->assertTrue(Version::isNewer('0.2.0', '0.2.0-beta.1'));
        $this->assertTrue(Version::isPrerelease('0.2.0-beta.1'));
        $this->expectException(UpdateException::class);
        Version::normalize('latest');
    }

    public function test_the_checksum_of_the_archive_is_read_from_the_published_list_and_compared(): void
    {
        $verifier = new PackageVerifier;
        $file = $this->installationDirectory.'/a.zip';
        file_put_contents($file, 'archive');
        $hash = hash('sha256', 'archive');
        $list = str_repeat('0', 64)."  install.php\n{$hash}  perysee.zip\n";

        $this->assertSame($hash, $verifier->expectedHash($list));
        $verifier->assertMatches($file, strtoupper($hash));
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('does not match');
        $verifier->assertMatches($file, str_repeat('a', 64));
    }

    public function test_a_checksum_list_without_the_archive_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        (new PackageVerifier)->expectedHash(str_repeat('a', 64)."  install.php\n");
    }

    public function test_a_valid_release_is_unpacked_into_the_staging_folder(): void
    {
        $result = (new ArchiveExtractor)->extract($this->archive($this->release()), $this->installationDirectory.'/staged');

        $this->assertSame('0.2.0', $result['version']);
        $this->assertFileExists($this->installationDirectory.'/staged/vendor/autoload.php');
        $this->assertFileExists($this->installationDirectory.'/staged/public/build/manifest.json');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function unsafeArchives(): array
    {
        return [
            'path traversal' => [['perysee/../escape.php' => 'x'], 'unsafe path'],
            'outside the root' => [['other/file.php' => 'x'], 'outside perysee/'],
            'environment file' => [['perysee/.env' => 'SECRET=1'], 'local state'],
            'module folder' => [['perysee/modules/x/module.json' => '{}'], 'local state'],
            'storage content' => [['perysee/storage/logs/laravel.log' => 'x'], 'local state'],
            'backslash' => [['perysee/app\\evil.php' => 'x'], 'unsafe path'],
            'missing front controller' => [['perysee/artisan' => 'x'], 'incomplete'],
        ];
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[DataProvider('unsafeArchives')]
    public function test_an_unsafe_or_incomplete_archive_is_refused_before_anything_is_written(array $extra, string $reason): void
    {
        $files = str_contains($reason, 'incomplete') ? $extra : $this->release() + $extra;
        $staged = $this->installationDirectory.'/staged';

        try {
            (new ArchiveExtractor)->extract($this->archive($files), $staged);
            $this->fail('The archive should have been refused.');
        } catch (UpdateException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->installationDirectory.'/escape.php');
    }

    public function test_an_archive_without_a_valid_version_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('release.json');
        (new ArchiveExtractor)->extract($this->archive(['perysee/release.json' => '{"version":"nightly"}'] + $this->release()), $this->installationDirectory.'/staged');
    }

    private function tree(string $root, array $files): void
    {
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root.'/'.$path), 0755, true);
            file_put_contents($root.'/'.$path, $contents);
        }
    }

    public function test_swapping_replaces_the_code_and_keeps_the_local_state_then_rollback_restores_everything(): void
    {
        $live = $this->installationDirectory.'/live';
        $staged = $this->installationDirectory.'/staged';
        $previous = $this->installationDirectory.'/previous';
        $this->tree($live, ['.env' => 'APP_KEY=keep', 'storage/app/data.txt' => 'keep', 'modules/m/module.json' => 'keep', 'bootstrap/cache/packages.php' => 'cache', 'bootstrap/app.php' => 'old', 'app/A.php' => 'old', 'app/Removed.php' => 'old', 'public/index.php' => 'old', 'public/uploads/user.png' => 'keep', 'vendor/autoload.php' => 'old', 'artisan' => 'old']);
        $this->tree($staged, ['bootstrap/app.php' => 'new', 'app/A.php' => 'new', 'public/index.php' => 'new', 'public/build/app.js' => 'new', 'vendor/autoload.php' => 'new', 'artisan' => 'new', 'release.json' => '{}']);
        $swapper = new CodeSwapper;

        $this->assertContains('public/index.php', $swapper->units($staged));
        $this->assertNotContains('public', $swapper->units($staged));
        $this->assertSame([], $swapper->problems($staged, $live, $previous));
        $swapper->swap($staged, $live, $previous);

        $this->assertSame('new', file_get_contents($live.'/app/A.php'));
        $this->assertFileDoesNotExist($live.'/app/Removed.php');
        $this->assertSame('new', file_get_contents($live.'/artisan'));
        $this->assertSame('new', file_get_contents($live.'/public/index.php'));
        $this->assertSame('APP_KEY=keep', file_get_contents($live.'/.env'));
        $this->assertSame('keep', file_get_contents($live.'/storage/app/data.txt'));
        $this->assertSame('keep', file_get_contents($live.'/modules/m/module.json'));
        $this->assertSame('cache', file_get_contents($live.'/bootstrap/cache/packages.php'));
        $this->assertSame('keep', file_get_contents($live.'/public/uploads/user.png'));

        $swapper->rollback($live, $previous);

        $this->assertSame('old', file_get_contents($live.'/app/A.php'));
        $this->assertSame('old', file_get_contents($live.'/app/Removed.php'));
        $this->assertSame('old', file_get_contents($live.'/artisan'));
        $this->assertSame('old', file_get_contents($live.'/vendor/autoload.php'));
        $this->assertSame('old', file_get_contents($live.'/bootstrap/app.php'));
        $this->assertFileDoesNotExist($live.'/public/build/app.js');
        $this->assertSame('keep', file_get_contents($live.'/public/uploads/user.png'));
        $this->assertSame('APP_KEY=keep', file_get_contents($live.'/.env'));
        $this->assertFalse($swapper->hasPrevious($previous));
    }

    public function test_a_swap_that_fails_midway_puts_back_what_it_already_moved(): void
    {
        $live = $this->installationDirectory.'/live';
        $staged = $this->installationDirectory.'/staged';
        $this->tree($live, ['app/A.php' => 'old', 'artisan' => 'old', 'public' => 'blocker']);
        $this->tree($staged, ['app/A.php' => 'new', 'artisan' => 'new', 'public/late.php' => 'new']);
        // The last unit cannot be placed: a file sits where the public/ folder should be.

        try {
            (new CodeSwapper)->swap($staged, $live, $this->installationDirectory.'/previous');
            $this->fail('The swap should have failed.');
        } catch (UpdateException $exception) {
            $this->assertStringContainsString('previous version was restored', $exception->getMessage());
        }

        $this->assertSame('old', file_get_contents($live.'/app/A.php'));
        $this->assertSame('old', file_get_contents($live.'/artisan'));
        $this->assertSame('blocker', file_get_contents($live.'/public'));
    }

    public function test_the_php_dump_is_complete_and_escapes_values(): void
    {
        $site = Website::factory()->create(['name' => "Quote's \"site\" \\ end"]);
        DB::table('analytics_state')->insert(['name' => 'blob', 'value' => '"x"', 'updated_at' => now('UTC')]);
        $path = $this->installationDirectory.'/dump.sql';

        (new PhpDump)->dump($path);

        $sql = (string) file_get_contents($path);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `websites`;', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `analytics_state`', $sql);
        if (DB::getDriverName() !== 'sqlite') {
            // The dump is MariaDB SQL: its restoration is covered by the documented procedure, not replayed here.
            $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1;', $sql);

            return;
        }
        $restored = new PDO('sqlite::memory:');
        $restored->exec($sql);
        $this->assertSame("Quote's \"site\" \\ end", $restored->query('SELECT name FROM websites WHERE id = '.$site->id)->fetchColumn());
        $this->assertSame((int) DB::table('migrations')->count(), (int) $restored->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        $this->assertSame('"x"', $restored->query("SELECT value FROM analytics_state WHERE name = 'blob'")->fetchColumn());
    }

    public function test_the_native_dump_writes_a_complete_dump_with_the_client_tool_of_the_server(): void
    {
        $native = new NativeDump;
        if (! $native->available()) {
            $this->markTestSkipped('No MariaDB client tool on this machine, or not a MariaDB connection.');
        }
        $path = $this->installationDirectory.'/native.sql';

        $native->dump($path);

        $this->assertStringContainsString('CREATE TABLE `websites`', (string) file_get_contents($path));
        $this->assertStringContainsString('CREATE TABLE `analytics_state`', (string) file_get_contents($path));
    }

    public function test_a_backup_holds_the_dump_the_environment_file_and_the_private_files_but_not_other_backups(): void
    {
        config(['perysee.environment_path' => $this->installationDirectory.'/.env']);
        file_put_contents($this->installationDirectory.'/.env', 'APP_KEY=base64:keep');
        $private = storage_path('app/private');
        @mkdir($private.'/reports', 0755, true);
        file_put_contents($private.'/reports/r.csv', 'a,b');
        $backup = $this->app->make(Backup::class);

        $result = $backup->create('0.1.1', 'php');

        try {
            $this->assertSame('php', $result['method']);
            $this->assertFileExists($result['path'].'/'.$result['database']);
            $this->assertStringContainsString('CREATE TABLE', (string) gzdecode((string) file_get_contents($result['path'].'/'.$result['database'])));
            $this->assertSame('APP_KEY=base64:keep', file_get_contents($result['path'].'/env'));
            $this->assertFileExists($result['path'].'/private-files/reports/r.csv');
            $this->assertDirectoryDoesNotExist($result['path'].'/private-files/updates');
            $this->assertSame(0600, fileperms($result['path'].'/env') & 0777);
            $this->assertSame(hash_file('sha256', $result['path'].'/'.$result['database']), $result['sha256']);
        } finally {
            (new CodeSwapper)->remove($result['path']);
            @unlink($private.'/reports/r.csv');
        }
    }

    public function test_a_dump_the_administrator_made_is_recorded_and_a_missing_one_is_refused(): void
    {
        $dump = $this->installationDirectory.'/mine.sql';
        file_put_contents($dump, 'CREATE TABLE t (id int);');
        $backup = $this->app->make(Backup::class);

        $result = $backup->create('0.1.1', 'external', $dump);
        try {
            $this->assertSame('external', $result['method']);
            $this->assertSame(hash('sha256', 'CREATE TABLE t (id int);'), $result['sha256']);
            $this->expectException(UpdateException::class);
            $backup->create('0.1.1', 'external', $this->installationDirectory.'/missing.sql');
        } finally {
            (new CodeSwapper)->remove($result['path']);
            foreach (glob(storage_path('app/private/updates/backups/*')) ?: [] as $leftover) {
                (new CodeSwapper)->remove($leftover);
            }
        }
    }

    public function test_an_unavailable_dump_method_is_named_in_the_error(): void
    {
        if ((new NativeDump)->available()) {
            $this->markTestSkipped('The native dump method is available on this machine.');
        }
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('"native" dump method is not available');
        $this->app->make(Backup::class)->create('0.1.1', 'native');
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        $backup = $this->app->make(Backup::class);
        $root = $backup->root().'/backups';
        foreach (['20260101-000000-v0.1.0', '20260102-000000-v0.1.0', '20260103-000000-v0.1.0', '20260104-000000-v0.1.0', '20260105-000000-v0.1.0'] as $name) {
            mkdir($root.'/'.$name, 0700, true);
        }

        $backup->prune();

        $this->assertSame(['20260103-000000-v0.1.0', '20260104-000000-v0.1.0', '20260105-000000-v0.1.0'], array_map('basename', $backup->all()));
        (new CodeSwapper)->remove($root);
    }
}
