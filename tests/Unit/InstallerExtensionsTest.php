<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InstallerExtensionsTest extends TestCase
{
    /** @return list<string> */
    private function list(string $file, string $variable): array
    {
        $this->assertSame(1, preg_match('/\$'.$variable.' = \[([^\]]+)\];/', (string) file_get_contents(__DIR__.'/../../'.$file), $match), $file);

        return array_map(fn (string $name): string => trim($name, " '"), explode(',', $match[1]));
    }

    public function test_the_command_line_installer_checks_what_the_browser_installer_and_the_two_factor_packages_need(): void
    {
        $cli = $this->list('scripts/install.php', 'extensions');
        $browser = $this->list('app/Installation/Diagnostics.php', 'extensions');

        $this->assertContains('iconv', $cli);
        $this->assertContains('xmlwriter', $cli);
        $this->assertSame([], array_values(array_diff($browser, $cli)), 'The CLI installer must check every extension the browser installer checks.');
    }
}
