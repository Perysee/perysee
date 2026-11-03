<?php

namespace App\Console\Commands;

use App\Updates\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FinalizeUpdate extends Command
{
    protected $signature = 'perysee:update:finalize {--expect= : The version these files must be}';

    protected $description = 'Second half of an update, run by the new version in a fresh process: migrations, caches, reopening';

    protected $hidden = true;

    public function handle(): int
    {
        $expected = (string) $this->option('expect');
        if ($expected === '' || Version::normalize($expected) !== Version::normalize((string) config('perysee.version'))) {
            $this->error('These files are version '.config('perysee.version').', not '.$expected.'.');

            return self::FAILURE;
        }
        $this->line('Applying migrations');
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            $this->error('Migrations failed: '.trim(Artisan::output()));

            return self::FAILURE;
        }
        Artisan::call('optimize:clear');
        DB::table('analytics_state')->count();
        $this->line('Reopening the instance');
        Artisan::call('up');

        return self::SUCCESS;
    }
}
