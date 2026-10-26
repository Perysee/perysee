<?php

namespace Tests\Feature;

use App\Analytics\Privacy\NormalizePath;
use Tests\TestCase;

class PathNormalizationTest extends TestCase
{
    public function test_paths_are_private_and_canonical_on_both_sides_of_collection(): void
    {
        $normalize = app(NormalizePath::class);
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/paths.json')), true) as $fixture) {
            $this->assertSame($fixture['expected'], $normalize($fixture['input']));
            $this->assertSame($fixture['expected'], $normalize($normalize($fixture['input'])));
        }
    }
}
