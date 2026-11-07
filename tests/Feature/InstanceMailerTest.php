<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Mail\InstanceMailer;
use App\Mail\InstanceTestMail;
use App\Mail\MailNotSent;
use App\Mail\MailSettings;
use App\Mail\SiteInvitationMail;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class InstanceMailerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['mail.default' => 'log', 'mail.mailers.smtp.password' => null]);
    }

    private function mailer(): InstanceMailer
    {
        return app(InstanceMailer::class);
    }

    private function save(array $override = []): void
    {
        app(MailSettings::class)->save($override + ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password' => self::SECRET, 'from_address' => 'noreply@example.com', 'from_name' => 'Acme']);
    }

    private function invitation(): SiteInvitationMail
    {
        return new SiteInvitationMail('http://localhost/invitations/abc', 'Site', 'viewer', 'Ann', 'Nov 14, 2026', 'Perysee');
    }

    public function test_configured_means_saved_complete_settings_or_a_real_environment_mailer(): void
    {
        $this->assertFalse($this->mailer()->configured());

        config(['mail.default' => 'array']);
        $this->assertFalse($this->mailer()->configured());

        config(['mail.default' => 'smtp']);
        $this->assertTrue($this->mailer()->configured());

        config(['mail.default' => 'log']);
        $this->save();
        $this->assertTrue($this->mailer()->configured());

        $this->save(['enabled' => false]);
        $this->assertFalse($this->mailer()->configured());
    }

    public function test_send_applies_the_saved_settings_then_sends_synchronously(): void
    {
        $this->save();
        Mail::fake();

        $this->mailer()->send('a@example.com', $this->invitation());

        Mail::assertSent(SiteInvitationMail::class, fn (SiteInvitationMail $mail): bool => $mail->hasTo('a@example.com'));
        Mail::assertNothingQueued();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
    }

    public function test_a_failed_send_throws_a_generic_exception_and_reports_nothing_that_looks_like_a_credential(): void
    {
        $this->save();
        Mail::shouldReceive('forgetMailers')->andReturnSelf();
        Mail::shouldReceive('to->send')->andThrow(new TransportException('Failed to authenticate on SMTP server with username "mailer" and password "'.self::SECRET.'" token=abcDEF123456789012345678'));
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported): bool {
            $reported[] = $e->getMessage();

            return false;
        });

        try {
            $this->mailer()->send('a@example.com', $this->invitation());
            $this->fail('A MailNotSent exception was expected.');
        } catch (MailNotSent $exception) {
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertCount(1, $reported);
        $this->assertStringNotContainsString(self::SECRET, $reported[0]);
        $this->assertStringNotContainsString('mailer"', $reported[0]);
        $this->assertStringNotContainsString('abcDEF123456789012345678', $reported[0]);
    }

    #[DataProvider('failures')]
    public function test_the_test_message_reports_a_category_and_never_the_server_text(string $message, string $category): void
    {
        $this->save();
        Mail::shouldReceive('forgetMailers')->andReturnSelf();
        Mail::shouldReceive('to->send')->andThrow(new TransportException($message));

        $result = $this->mailer()->test('admin@example.com');

        $this->assertSame(['category' => $category], $result);
    }

    public static function failures(): array
    {
        return [
            'refused' => ['Connection could not be established with host "127.0.0.1:2599": stream_socket_client(): Unable to connect to 127.0.0.1:2599 (Connection refused)', 'connection'],
            'timeout' => ['Connection to "smtp.example.com:587" timed out.', 'connection'],
            'dns' => ['Connection could not be established with host "nope.invalid:25": php_network_getaddresses: getaddrinfo failed', 'connection'],
            'auth' => ['Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".".', 'authentication'],
            'auth required' => ['Expected response code "250" but got code "530", with message "530 Authentication required".', 'authentication'],
            'sender' => ['Expected response code "250" but got code "553", with message "553 5.7.1 Sender address rejected: not owned by user".', 'sender_rejected'],
            'relay' => ['Expected response code "250" but got code "550", with message "550 Relay access denied for sender".', 'sender_rejected'],
            'recipient' => ['Expected response code "250" but got code "550", with message "550 Mailbox unavailable".', 'other'],
            'something else' => ['Boom', 'other'],
        ];
    }

    public function test_the_test_message_is_sent_with_unsaved_values_and_succeeds(): void
    {
        Mail::fake();
        $values = app(MailSettings::class)->build(['enabled' => false, 'host' => 'smtp.typed.test', 'port' => 2525, 'encryption' => 'none', 'username' => 'u', 'password' => 'typed-secret', 'from_address' => 'from@typed.test']);

        $result = $this->mailer()->test('admin@example.com', $values);

        $this->assertSame(['category' => 'sent'], $result);
        $this->assertSame('smtp.typed.test', config('mail.mailers.smtp.host'));
        $this->assertSame('typed-secret', config('mail.mailers.smtp.password'));
        Mail::assertSent(InstanceTestMail::class, fn ($mail): bool => $mail->hasTo('admin@example.com'));
    }

    public function test_without_any_real_mailer_the_test_says_so_instead_of_pretending(): void
    {
        Mail::fake();

        $this->assertSame(['category' => 'not_configured'], $this->mailer()->test('admin@example.com'));
        Mail::assertNothingSent();
    }
}
