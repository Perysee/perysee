<?php

namespace Tests\Feature;

use App\Installation\Diagnostics;
use App\Updates\CodeSwapper;
use App\Updates\Preflight;
use Tests\TestCase;

class PreflightExtensionsTest extends TestCase
{
    /** @param list<string> $missing */
    private function preflight(array $missing): Preflight
    {
        $diagnostics = new class($missing) extends Diagnostics
        {
            /** @param list<string> $missing */
            public function __construct(private array $missing) {}

            protected function extensionLoaded(string $extension): bool
            {
                return ! in_array($extension, $this->missing, true);
            }
        };

        return new Preflight(app(CodeSwapper::class), $diagnostics);
    }

    /** @return list<string> */
    private function extensionProblems(Preflight $preflight): array
    {
        $archive = tempnam(sys_get_temp_dir(), 'preflight');
        $live = sys_get_temp_dir().'/preflight-live-'.uniqid();
        mkdir($live);

        try {
            $problems = $preflight->problems($live, $archive, $live, $live.'-previous', '99.0.0');
        } finally {
            @unlink($archive);
            @rmdir($live);
        }

        return array_values(array_filter($problems, fn (string $problem) => str_contains($problem, 'PHP extension')));
    }

    public function test_a_host_with_every_required_extension_gets_no_extension_problem(): void
    {
        $this->assertSame([], $this->extensionProblems($this->preflight([])));
    }

    public function test_each_missing_extension_is_named_in_its_own_problem(): void
    {
        $problems = $this->extensionProblems($this->preflight(['xmlwriter', 'iconv']));

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('iconv', implode("\n", $problems));
        $this->assertStringContainsString('xmlwriter', implode("\n", $problems));
    }

    public function test_the_required_extensions_include_those_of_two_factor_enrolment(): void
    {
        $this->assertContains('iconv', (new Diagnostics)->requiredExtensions());
        $this->assertContains('xmlwriter', (new Diagnostics)->requiredExtensions());
    }
}
