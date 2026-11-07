<?php

namespace Tests\Unit;

use App\Support\CliPhp;
use PHPUnit\Framework\TestCase;

class CliPhpTest extends TestCase
{
    /** A web request on $sapi, where only the paths in $present are executable files. */
    private function web(string $sapi, string $binary, string $bindir, string $version, array $present, bool $restricted = false): CliPhp
    {
        return new CliPhp($sapi, $binary, $bindir, $version, fn (string $path): bool => in_array($path, $present, true), $restricted);
    }

    public function test_the_command_line_php_that_runs_the_command_is_used_as_is(): void
    {
        $php = new CliPhp('cli', '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin', '8.3', fn () => false, false);

        $this->assertSame('/opt/cpanel/ea-php83/root/usr/bin/php', $php->path());
    }

    public function test_a_litespeed_wrapper_is_never_used_the_cli_built_with_the_running_php_is(): void
    {
        $php = $this->web('litespeed', '/usr/local/bin/lsphp', '/opt/alt/php83/usr/bin', '8.3', ['/opt/alt/php83/usr/bin/php', '/usr/local/bin/php']);

        $this->assertSame('/opt/alt/php83/usr/bin/php', $php->path());
    }

    public function test_a_versioned_binary_wins_over_the_system_alternative_that_may_be_another_version(): void
    {
        $php = $this->web('fpm-fcgi', '/usr/sbin/php-fpm8.3', '/usr/bin', '8.3', ['/usr/bin/php', '/usr/bin/php8.3']);

        $this->assertSame('/usr/bin/php8.3', $php->path());
    }

    public function test_the_official_docker_image_uses_its_own_php(): void
    {
        $php = $this->web('apache2handler', '/usr/local/bin/php', '/usr/local/bin', '8.4', ['/usr/local/bin/php']);

        $this->assertSame('/usr/local/bin/php', $php->path());
    }

    public function test_cpanel_easyapache_is_found_by_the_version_of_the_running_php(): void
    {
        $php = $this->web('litespeed', '/usr/local/bin/lsphp', '/usr/sbin', '8.3', ['/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php']);

        $this->assertSame('/opt/cpanel/ea-php83/root/usr/bin/php', $php->path());
    }

    public function test_cloudlinux_and_plesk_layouts_are_found_too(): void
    {
        $this->assertSame('/opt/alt/php84/usr/bin/php', $this->web('litespeed', '/x/lsphp', '/x', '8.4', ['/opt/alt/php84/usr/bin/php'])->path());
        $this->assertSame('/opt/plesk/php/8.3/bin/php', $this->web('fpm-fcgi', '/x/php-fpm', '/x', '8.3', ['/opt/plesk/php/8.3/bin/php'])->path());
    }

    public function test_a_php_of_another_version_is_never_chosen(): void
    {
        $php = $this->web('litespeed', '/x/lsphp', '/x', '8.3', ['/opt/cpanel/ea-php81/root/usr/bin/php', '/opt/alt/php82/usr/bin/php']);

        $this->assertSame('php', $php->path());
    }

    public function test_when_nothing_is_found_the_plain_command_name_is_left_to_the_hosts_path(): void
    {
        $this->assertSame('php', $this->web('cgi-fcgi', '/x/php-cgi', '/x', '8.3', [])->path());
    }

    public function test_with_open_basedir_nothing_is_probed_and_the_build_directory_cli_is_trusted(): void
    {
        $php = new CliPhp('litespeed', '/usr/local/bin/lsphp', '/opt/alt/php83/usr/bin', '8.3', fn () => throw new \LogicException('the filesystem must not be probed'), true);

        $this->assertSame('/opt/alt/php83/usr/bin/php', $php->path());
    }

    public function test_the_cron_line_runs_every_minute_keeps_errors_and_quotes_paths(): void
    {
        $php = $this->web('litespeed', '/x/lsphp', '/opt/alt/php83/usr/bin', '8.3', ['/opt/alt/php83/usr/bin/php']);

        $this->assertSame(
            "* * * * * cd '/home/account/perysee' && '/opt/alt/php83/usr/bin/php' artisan schedule:run > /dev/null 2>> storage/logs/cron.log",
            $php->cronLine('/home/account/perysee'),
        );
        $this->assertStringContainsString("cd '/home/o'\\''brien/app'", $php->cronLine("/home/o'brien/app"));
    }
}
