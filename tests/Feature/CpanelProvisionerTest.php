<?php

namespace Tests\Feature;

use App\Installation\Cpanel\CpanelProvisioner;
use App\Installation\Cpanel\ProvisioningException;
use App\Installation\Cpanel\UapiClient;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CpanelProvisionerTest extends TestCase
{
    private const HOME = '/home/cpuser';

    private const TARGET = '/home/cpuser/perysee';

    /** @var list<array{call: string, args: array<string, string>, stdin: bool}> */
    private array $calls = [];

    private function ok(mixed $data = null): array
    {
        return ['result' => ['status' => 1, 'data' => $data, 'errors' => null]];
    }

    private function failure(string $message): array
    {
        return ['result' => ['status' => 0, 'data' => null, 'errors' => [$message]]];
    }

    /** Answers uapi calls the way a cPanel account would; $overrides replace the answer of one "Module function". */
    private function cpanel(array $overrides = [], bool $stdinSupported = true): void
    {
        $defaults = [
            'Variables get_user_information' => $this->ok(['user' => 'cpuser', 'home' => self::HOME]),
            'Mysql get_restrictions' => $this->ok(['prefix' => 'cpuser_', 'max_database_name_length' => 63, 'max_username_length' => 31]),
            'LangPHP php_get_installed_versions' => $this->ok(['ea-php74', 'ea-php83', 'ea-php84']),
            'DomainInfo domains_data' => $this->ok([
                'main_domain' => ['domain' => 'example.com', 'documentroot' => self::HOME.'/public_html'],
                'addon_domains' => [['domain' => 'other.org', 'documentroot' => self::HOME.'/other']],
                'sub_domains' => [['domain' => 'blog.example.com', 'documentroot' => self::HOME.'/blog']],
                'parked_domains' => [],
            ]),
        ];
        $answers = array_merge($defaults, $overrides);
        Process::fake(function (PendingProcess $process) use ($answers, $stdinSupported) {
            $command = array_values((array) $process->command);
            $viaStdin = in_array('--input=json', $command, true);
            $words = array_values(array_filter(array_slice($command, 1), fn (string $word) => ! str_starts_with($word, '--')));
            $key = $words[0].' '.$words[1];
            $args = [];
            foreach (array_slice($words, 2) as $pair) {
                [$name, $value] = explode('=', $pair, 2);
                $args[$name] = $value;
            }
            if ($viaStdin) {
                if (! $stdinSupported) {
                    return Process::result(output: 'Unknown option: input', exitCode: 1);
                }
                $args = json_decode((string) $process->input, true) ?? [];
            }
            $this->calls[] = ['call' => $key, 'args' => $args, 'stdin' => $viaStdin, 'command' => implode(' ', $command)];
            $answer = $answers[$key] ?? $this->ok();

            return Process::result(output: json_encode(is_callable($answer) ? $answer($args) : $answer));
        });
    }

    private function provisioner(): CpanelProvisioner
    {
        return new CpanelProvisioner(new UapiClient('/usr/local/cpanel/bin/uapi'));
    }

    private function names(): array
    {
        return array_column($this->calls, 'call');
    }

    public function test_it_creates_the_subdomain_database_and_user_and_selects_the_newest_php(): void
    {
        $this->cpanel();

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $this->assertSame('localhost', $resources->databaseHost);
        $this->assertStringStartsWith('cpuser_', $resources->databaseName);
        $this->assertStringStartsWith('cpuser_', $resources->databaseUser);
        $this->assertLessThanOrEqual(31, strlen($resources->databaseUser));
        $this->assertSame(32, strlen($resources->databasePassword));
        $this->assertSame('/opt/cpanel/ea-php84/root/usr/bin/php', $resources->phpCli);
        $subdomain = collect($this->calls)->firstWhere('call', 'SubDomain addsubdomain');
        $this->assertSame(['domain' => 'stats', 'rootdomain' => 'example.com', 'dir' => 'perysee/public'], collect($subdomain['args'])->only(['domain', 'rootdomain', 'dir'])->all());
        $php = collect($this->calls)->firstWhere('call', 'LangPHP php_set_vhost_versions');
        $this->assertSame('ea-php84', $php['args']['version']);
        $this->assertSame('stats.example.com', $php['args']['vhost']);
        $privileges = collect($this->calls)->firstWhere('call', 'Mysql set_privileges_on_database');
        $this->assertSame($resources->databaseUser, $privileges['args']['user']);
        $this->assertSame($resources->databaseName, $privileges['args']['database']);
        $this->assertSame([], $resources->warnings);
    }

    public function test_the_database_password_never_appears_on_a_command_line(): void
    {
        $this->cpanel();

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $create = collect($this->calls)->firstWhere('call', 'Mysql create_user');
        $this->assertTrue($create['stdin']);
        $this->assertSame($resources->databasePassword, $create['args']['password']);
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString($resources->databasePassword, $call['command']);
        }
    }

    public function test_it_falls_back_to_arguments_when_uapi_cannot_read_stdin(): void
    {
        $this->cpanel(stdinSupported: false);

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $create = collect($this->calls)->firstWhere('call', 'Mysql create_user');
        $this->assertFalse($create['stdin']);
        $this->assertSame($resources->databasePassword, $create['args']['password']);
    }

    public function test_a_domain_that_already_exists_elsewhere_is_never_taken_over(): void
    {
        $this->cpanel();

        try {
            $this->provisioner()->provision('blog.example.com', self::TARGET);
            $this->fail('Expected a refusal.');
        } catch (ProvisioningException $exception) {
            $this->assertStringContainsString('blog.example.com already exists', $exception->getMessage());
        }

        $this->assertNotContains('SubDomain addsubdomain', $this->names());
        $this->assertNotContains('Mysql create_database', $this->names());
    }

    public function test_a_domain_already_pointing_at_the_application_is_reused(): void
    {
        $this->cpanel(['DomainInfo domains_data' => $this->ok([
            'main_domain' => ['domain' => 'example.com', 'documentroot' => self::HOME.'/public_html'],
            'addon_domains' => [], 'parked_domains' => [],
            'sub_domains' => [['domain' => 'stats.example.com', 'documentroot' => self::TARGET.'/public']],
        ])]);

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $this->assertNotContains('SubDomain addsubdomain', $this->names());
        $this->assertNull($resources->subdomain);
    }

    public function test_a_domain_outside_the_account_lists_the_available_ones(): void
    {
        $this->cpanel();

        try {
            $this->provisioner()->provision('stats.elsewhere.net', self::TARGET);
            $this->fail('Expected a refusal.');
        } catch (ProvisioningException $exception) {
            $this->assertStringContainsString('example.com', $exception->getMessage());
            $this->assertStringContainsString('other.org', $exception->getMessage());
        }

        $this->assertNotContains('Mysql create_database', $this->names());
    }

    public function test_a_target_outside_the_home_directory_is_refused(): void
    {
        $this->cpanel();

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('inside your home directory');

        $this->provisioner()->provision('stats.example.com', '/srv/perysee');
    }

    public function test_a_failure_removes_only_what_this_run_created(): void
    {
        $this->cpanel(['Mysql set_privileges_on_database' => $this->failure('Permission denied')]);

        try {
            $this->provisioner()->provision('stats.example.com', self::TARGET);
            $this->fail('Expected a failure.');
        } catch (ProvisioningException $exception) {
            $this->assertStringContainsString('Permission denied', $exception->getMessage());
        }

        $this->assertSame(['SubDomain delsubdomain', 'Mysql delete_user', 'Mysql delete_database'], array_slice($this->names(), -3));
    }

    public function test_a_reused_domain_is_not_deleted_when_the_run_fails(): void
    {
        $this->cpanel([
            'DomainInfo domains_data' => $this->ok([
                'main_domain' => ['domain' => 'example.com', 'documentroot' => self::HOME.'/public_html'],
                'addon_domains' => [], 'parked_domains' => [],
                'sub_domains' => [['domain' => 'stats.example.com', 'documentroot' => self::TARGET.'/public']],
            ]),
            'Mysql create_user' => $this->failure('User limit reached'),
        ]);

        try {
            $this->provisioner()->provision('stats.example.com', self::TARGET);
        } catch (ProvisioningException) {
        }

        $this->assertNotContains('SubDomain delsubdomain', $this->names());
        $this->assertContains('Mysql delete_database', $this->names());
    }

    public function test_without_php_8_3_the_web_version_is_left_alone_with_a_warning(): void
    {
        $this->cpanel(['LangPHP php_get_installed_versions' => $this->ok(['ea-php74', 'ea-php82'])]);

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $this->assertNotContains('LangPHP php_set_vhost_versions', $this->names());
        $this->assertStringContainsString('PHP 8.3', $resources->warnings[0]);
    }

    public function test_a_php_selector_that_refuses_the_change_is_a_warning_not_a_failure(): void
    {
        $this->cpanel(['LangPHP php_set_vhost_versions' => $this->failure('Managed by CloudLinux')]);

        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $this->assertStringContainsString('Managed by CloudLinux', $resources->warnings[0]);
        $this->assertSame('localhost', $resources->databaseHost);
    }

    public function test_the_scheduler_cron_runs_every_minute_with_the_cli_that_matches_the_web_php(): void
    {
        $this->cpanel();
        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $warnings = $this->provisioner()->schedule($resources, self::TARGET);

        $cron = collect($this->calls)->firstWhere('call', 'Cron add_line');
        $this->assertSame('* * * * *', $cron['args']['minute'].' '.$cron['args']['hour'].' '.$cron['args']['day'].' '.$cron['args']['month'].' '.$cron['args']['weekday']);
        $this->assertSame("cd '/home/cpuser/perysee' && '/opt/cpanel/ea-php84/root/usr/bin/php' artisan schedule:run >> storage/logs/cron.log 2>&1", $cron['args']['command']);
        $this->assertSame([], $warnings);
    }

    public function test_a_cron_failure_is_reported_with_the_line_to_add_by_hand(): void
    {
        $this->cpanel(['Cron add_line' => $this->failure('Cron is disabled')]);
        $resources = $this->provisioner()->provision('stats.example.com', self::TARGET);

        $warnings = $this->provisioner()->schedule($resources, self::TARGET);

        $this->assertStringContainsString('Cron is disabled', $warnings[0]);
        $this->assertStringContainsString('schedule:run', $warnings[0]);
    }
}
