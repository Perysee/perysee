<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CachePurgeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/perysee-cache-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/ab/cd', 0777, true);
        config(['cache.stores.file.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function entry(string $seed, int $expiresAt, string $first = 'ab', string $second = 'cd'): string
    {
        @mkdir($this->directory.'/'.$first.'/'.$second, 0777, true);
        $file = $this->directory.'/'.$first.'/'.$second.'/'.sha1($seed);
        file_put_contents($file, $expiresAt.serialize('value'));

        return $file;
    }

    public function test_expired_entries_are_deleted_and_the_others_kept(): void
    {
        $expired = $this->entry('expired', time() - 10);
        $live = $this->entry('live', time() + 600);
        $forever = $this->entry('forever', 9999999999);
        $notAnEntry = $this->directory.'/ab/cd/not-a-cache-entry';
        file_put_contents($notAnEntry, '0000000001junk');
        file_put_contents($this->directory.'/.gitignore', '*');

        $this->artisan('perysee:prune-cache')->assertSuccessful();

        $this->assertFileDoesNotExist($expired);
        $this->assertFileExists($live);
        $this->assertFileExists($forever);
        $this->assertFileExists($notAnEntry);
        $this->assertFileExists($this->directory.'/.gitignore');
        $this->assertDirectoryExists($this->directory.'/ab/cd', 'Directories are left to the store.');
    }

    public function test_a_file_that_does_not_start_with_a_timestamp_is_left_alone(): void
    {
        $file = $this->directory.'/ab/cd/'.sha1('odd');
        file_put_contents($file, 'not a timestamp');

        $this->artisan('perysee:prune-cache')->assertSuccessful();

        $this->assertFileExists($file);
    }

    public function test_a_run_deletes_at_most_the_limit_over_all_directories(): void
    {
        $files = [];
        foreach (range(1, 7) as $index) {
            $files[] = $this->entry('expired-'.$index, time() - 10, $index % 2 ? 'ab' : '0f', 'cd');
        }

        $this->artisan('perysee:prune-cache', ['--limit' => 3])->assertSuccessful();
        $this->assertCount(4, array_filter($files, 'file_exists'));

        $this->artisan('perysee:prune-cache', ['--limit' => 10])->assertSuccessful();
        $this->assertCount(0, array_filter($files, 'file_exists'));
    }

    public function test_a_missing_directory_is_not_an_error(): void
    {
        config(['cache.stores.file.path' => $this->directory.'/missing']);

        $this->artisan('perysee:prune-cache')->assertSuccessful();
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->artisan('perysee:prune-cache', ['--limit' => 0])->assertFailed();
        $this->artisan('perysee:prune-cache', ['--limit' => 10001])->assertFailed();
    }

    public function test_it_is_a_dedicated_hourly_command_that_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-cache'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
