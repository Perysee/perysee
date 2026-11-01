<?php

namespace App\Http\Controllers;

use App\Modules\ModuleRegistry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModuleAssetController extends Controller
{
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    /**
     * Serves a compiled file of an enabled module's dist/ directory. The URL carries the module version, so it is cached for a year.
     */
    public function __invoke(ModuleRegistry $registry, string $module, string $file): BinaryFileResponse
    {
        $installed = $registry->enabled()[$module] ?? null;
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        abort_unless($installed && isset(self::TYPES[$extension]) && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/D', $file) && ! str_contains($file, '..'), 404);
        $path = $installed->path.'/dist/'.$file;
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => self::TYPES[$extension], 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
