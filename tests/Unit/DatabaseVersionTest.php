<?php

namespace Tests\Unit;

use App\Installation\DatabaseVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseVersionTest extends TestCase
{
    public static function supported(): array
    {
        return [
            'oldest LTS' => ['10.11.6-MariaDB'],
            'current default' => ['11.4.8-MariaDB'],
            'Debian build' => ['11.4.5-MariaDB-0+deb12u1'],
            'newest LTS' => ['11.8.9-MariaDB-ubu2404'],
            'replication prefix some clients show' => ['5.5.5-10.11.6-MariaDB'],
        ];
    }

    #[DataProvider('supported')]
    public function test_supported_series_are_accepted(string $version): void
    {
        $this->assertTrue(DatabaseVersion::supports($version));
    }

    public static function unsupported(): array
    {
        return [
            'before native uuid' => ['10.6.18-MariaDB'],
            'old LTS' => ['10.5.25-MariaDB'],
            'rolling release between LTS' => ['11.5.2-MariaDB'],
            'not validated yet' => ['12.0.1-MariaDB'],
            'MySQL' => ['8.4.3'],
            'MySQL with a suffix' => ['8.0.39-0ubuntu0.22.04.1'],
            'nothing' => [''],
            'garbage' => ['MariaDB'],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_other_versions_are_refused(string $version): void
    {
        $this->assertFalse(DatabaseVersion::supports($version));
    }

    public function test_the_message_lists_every_supported_series(): void
    {
        $message = DatabaseVersion::requirement();

        $this->assertStringContainsString('10.11', $message);
        $this->assertStringContainsString('11.4', $message);
        $this->assertStringContainsString('11.8', $message);
        $this->assertStringContainsString('MariaDB', $message);
    }
}
