<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Mail\MailSettings;
use App\Mail\ModuleNotificationMail;
use App\Modules\Contracts\NotificationChannel;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use App\Modules\Notifications\Notification;
use App\Modules\Notifications\NotificationChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_abcdefghijklmnopqrstuvwxyz';

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.version' => '0.4.0', 'mail.default' => 'log']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(NotificationChannels::class);
    }

    private function install(string $id, bool $enabled = true): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode(['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0']));
        if ($enabled) {
            app(ModuleRegistry::class)->enable($id);
        }
    }

    private function channels(): NotificationChannels
    {
        return app(NotificationChannels::class);
    }

    private function recording(bool $configured = true, ?\Throwable $failure = null): NotificationChannel
    {
        return new class($configured, $failure) implements NotificationChannel
        {
            /** @var list<array{string, string}> */
            public array $sent = [];

            public function __construct(private bool $configured, private ?\Throwable $failure) {}

            public function label(): string
            {
                return "Rec\x07orded";
            }

            public function configured(): bool
            {
                return $this->configured;
            }

            public function send(Notification $notification, string $target): void
            {
                if ($this->failure) {
                    throw $this->failure;
                }
                $this->sent[] = [$notification->subject, $target];
            }
        };
    }

    private function note(): Notification
    {
        return new Notification('Heartbeat', 'The scheduler is silent.', 'warning', '/health');
    }

    public function test_a_module_declares_a_channel_and_others_can_send_through_it(): void
    {
        $this->install('alerts');
        $channel = $this->recording();
        $this->channels()->register('alerts', 'alerts.log', $channel);

        $this->assertTrue($this->channels()->send('alerts.log', "ops\n@example.com", $this->note()));

        $this->assertSame([['Heartbeat', 'ops @example.com']], $channel->sent);
        $this->assertContains(['key' => 'alerts.log', 'module' => 'alerts', 'label' => 'Rec orded', 'configured' => true], $this->channels()->available());
    }

    public function test_a_key_outside_the_prefix_of_the_module_or_a_duplicate_is_refused(): void
    {
        $this->channels()->register('alerts', 'alerts.log', $this->recording());

        foreach ([['alerts', 'other.log'], ['alerts', 'alerts.log'], ['alerts', 'core.mail'], ['alerts', 'alerts'], ['alerts', 'alerts.Bad Key'], ['evil', 'core.mail']] as [$module, $key]) {
            try {
                $this->channels()->register($module, $key, $this->recording());
                $this->fail("{$key} for {$module} must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_class_that_is_not_a_channel_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->channels()->register('alerts', 'alerts.bad', \stdClass::class);
    }

    public function test_sending_never_throws_and_secrets_never_reach_the_log_or_the_failure_record(): void
    {
        $this->install('alerts');
        $this->channels()->register('alerts', 'alerts.hook', $this->recording(failure: new RuntimeException('POST https://hooks.example.com failed, secret='.self::SECRET.' password: hunter2')));
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged): void {
            $logged[] = $message.json_encode(array_map(fn ($value) => $value instanceof \Throwable ? $value->getMessage() : $value, $context));
        });

        $result = $this->channels()->send('alerts.hook', 'target', $this->note());

        $this->assertFalse($result);
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::SECRET, $line);
            $this->assertStringNotContainsString('hunter2', $line);
        }
        $this->assertSame(1, app(ModuleFailures::class)->total('alerts'));
    }

    public function test_repeated_channel_failures_count_towards_the_quarantine_of_the_owner(): void
    {
        $this->install('alerts');
        config(['perysee.module_failures.threshold.beta' => 2]);
        $this->channels()->register('alerts', 'alerts.hook', $this->recording(failure: new RuntimeException('down')));

        $this->channels()->send('alerts.hook', 't', $this->note());
        $this->channels()->send('alerts.hook', 't', $this->note());

        app(ModuleRegistry::class)->refresh();
        $this->assertFalse(app(ModuleRegistry::class)->all()['alerts']->enabled);
        $this->assertFalse($this->channels()->send('alerts.hook', 't', $this->note()), 'A quarantined module no longer sends.');
    }

    public function test_an_unconfigured_unknown_or_disabled_channel_returns_false(): void
    {
        $this->install('alerts');
        $this->install('paused', enabled: false);
        $off = $this->recording(configured: false);
        $paused = $this->recording();
        $this->channels()->register('alerts', 'alerts.off', $off);
        $this->channels()->register('paused', 'paused.log', $paused);

        $this->assertFalse($this->channels()->send('alerts.off', 't', $this->note()));
        $this->assertFalse($this->channels()->send('paused.log', 't', $this->note()));
        $this->assertFalse($this->channels()->send('nobody.nothing', 't', $this->note()));
        $this->assertSame([], $off->sent);
        $this->assertSame([], $paused->sent);
        $this->assertNotContains('paused.log', array_column($this->channels()->available(), 'key'));
    }

    public function test_the_core_mail_channel_respects_the_instance_mail_settings(): void
    {
        Mail::fake();
        $this->assertFalse($this->channels()->send('core.mail', 'ops@example.com', $this->note()));
        Mail::assertNothingSent();
        $this->assertContains(['key' => 'core.mail', 'module' => 'core', 'label' => 'E-mail', 'configured' => false], $this->channels()->available());

        app(MailSettings::class)->save(['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password' => 'a-long-enough-password', 'from_address' => 'noreply@example.com', 'from_name' => 'Acme']);

        $this->assertTrue($this->channels()->send('core.mail', 'ops@example.com', $this->note()));
        Mail::assertSent(ModuleNotificationMail::class, fn ($mail): bool => $mail->hasTo('ops@example.com')
            && $mail->hasSubject('[Warning] Heartbeat')
            && str_contains($mail->render(), 'The scheduler is silent.')
            && str_contains($mail->render(), '/health'));
    }

    public function test_the_core_mail_channel_refuses_a_target_that_is_not_an_address(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->assertFalse($this->channels()->send('core.mail', 'not an address', $this->note()));
        Mail::assertNothingSent();
    }

    public function test_a_notification_is_plain_text_with_capped_lengths_and_an_internal_link_only(): void
    {
        $note = new Notification("Sub\r\nject: injected", str_repeat('é', 2500)."\x00\x1b", 'catastrophic', 'https://evil.example/');

        $this->assertStringNotContainsString("\n", $note->subject);
        $this->assertSame(Notification::BODY_LIMIT, mb_strlen($note->body));
        $this->assertSame('info', $note->level);
        $this->assertNull($note->url);
        foreach (['//evil.example', '/\\evil', 'javascript:alert(1)', '/a b'] as $url) {
            $this->assertNull((new Notification('s', 'b', 'info', $url))->url, $url);
        }
        $this->assertSame('/sites/1?x=1', (new Notification('s', 'b', 'info', '/sites/1?x=1'))->url);
        $this->assertSame("a\nb", (new Notification('s', "a\r\nb"))->body);
    }
}
