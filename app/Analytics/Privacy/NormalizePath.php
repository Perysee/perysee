<?php

namespace App\Analytics\Privacy;

class NormalizePath
{
    public function __invoke(string $path): string
    {
        $path = explode('?', explode('#', $path, 2)[0], 2)[0];
        $segments = explode('/', rawurldecode($path));
        foreach ($segments as &$segment) {
            if (! mb_check_encoding($segment, 'UTF-8') || preg_match('/%[a-f0-9]{2}/i', $segment) || preg_match('/[\x00-\x1f\x7f@?#]/u', $segment) || preg_match('/^[0-9]+$/', $segment) || preg_match('/^[a-f0-9-]{32,}$/i', $segment) || mb_strlen($segment) > 80) {
                $segment = ':redacted';
            }
        }

        return mb_substr('/'.ltrim(implode('/', $segments), '/'), 0, 512);
    }
}
