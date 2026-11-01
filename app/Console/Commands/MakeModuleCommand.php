<?php

namespace App\Console\Commands;

use App\Modules\InvalidManifest;
use App\Modules\Manifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'perysee:make-module {id : Lowercase module id, e.g. pdf-export} {--name= : Display name}';

    protected $description = 'Scaffold a module in the modules directory from the module template';

    public function handle(): int
    {
        $id = $this->argument('id');
        $name = $this->option('name') ?: Str::headline($id);
        if (! preg_match('/^[\p{L}\p{N} .\-]{1,60}$/u', $name)) {
            $this->error('The name may only contain letters, digits, spaces, dots and dashes (60 characters at most).');

            return self::FAILURE;
        }
        try {
            $manifest = Manifest::fromArray(['id' => $id, 'name' => $name, 'version' => '0.1.0', 'core' => config('perysee.version')]);
        } catch (InvalidManifest $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $target = rtrim(config('perysee.modules_path'), '/').'/'.$id;
        if (File::exists($target)) {
            $this->error("Module {$id} already exists at {$target}.");

            return self::FAILURE;
        }
        $replacements = ['{{id}}' => $id, '{{name}}' => $name, '{{Studly}}' => $manifest->namespaceSegment(), '{{core}}' => $manifest->core];
        $template = resource_path('module-template');
        foreach (File::allFiles($template, true) as $file) {
            $relative = substr($file->getRelativePathname(), 0, -strlen('.stub'));
            File::ensureDirectoryExists(dirname($target.'/'.$relative));
            File::put($target.'/'.$relative, strtr(File::get($file->getPathname()), $replacements));
        }
        $this->info("Module {$id} created in {$target}.");
        $this->line("Enable it with: php artisan perysee:modules enable {$id}");

        return self::SUCCESS;
    }
}
