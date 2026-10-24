<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

class GuideController extends Controller
{
    public function show(string $guide): View
    {
        $documents = [
            'application' => ['README.md', 'Application setup'],
            'tracking' => ['docs/INSTALLATION-SUIVI.md', 'Install Lueur'],
            'privacy' => ['docs/CONFIDENTIALITE.md', 'Privacy choices'],
        ];
        abort_unless(isset($documents[$guide]), 404);
        [$path, $title] = $documents[$guide];
        $source = file_get_contents(base_path($path));
        $links = [
            '../README.md' => 'application',
            'docs/INSTALLATION-SUIVI.md' => 'tracking', 'INSTALLATION-SUIVI.md' => 'tracking',
            'docs/CONFIDENTIALITE.md' => 'privacy', 'CONFIDENTIALITE.md' => 'privacy',
        ];
        foreach ($links as $link => $target) {
            $source = str_replace(']('.$link.')', ']('.route('guides.show', $target).')', $source);
        }
        $content = Str::markdown($source, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return view('guide', compact('title', 'content'));
    }
}
