<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Mail\InstanceTestMail;
use App\Mail\MailSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function form(array $override = []): array
    {
        return $override + ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password' => self::SECRET, 'from_address' => 'noreply@example.com', 'from_name' => 'Acme'];
    }

    public function test_only_administrators_reach_the_mail_screen_and_its_actions(): void
    {
        $this->get('/settings/mail')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->get('/settings/mail')->assertForbidden();
        $this->put('/settings/mail', $this->form())->assertForbidden();
        $this->delete('/settings/mail')->assertForbidden();
        $this->post('/settings/mail/test', $this->form() + ['recipient' => 'a@example.com'])->assertForbidden();
        $this->assertFalse(app(MailSettings::class)->configured());
    }

    public function test_the_screen_sends_the_non_secret_view_and_the_password_never_leaves_the_server(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/mail', $this->form())->assertRedirect('/settings/mail');

        $response = $this->get('/settings/mail');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('screen', 'settings.mail')->where('mail.state', 'saved')->where('mail.saved', true)->where('mail.host', 'smtp.example.com')->where('mail.password_set', true)
            ->where('mail.source.host', 'saved')->where('recipient', $admin->email)->where('links', fn ($links) => ($links['settings.mail'] ?? null) === '/settings/mail' && ($links['settings.mail.test'] ?? null) === '/settings/mail/test')->where('testResult', null)->missing('mail.password'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public function test_saving_validates_every_field(): void
    {
        $this->actingAs($this->admin());
        foreach ([
            ['host' => 'bad host!'], ['host' => str_repeat('a', 254)], ['host' => ''], ['host' => "smtp.example.com\r\nBcc: x"],
            ['port' => 0], ['port' => 70000], ['port' => 'abc'], ['encryption' => 'ssl3'],
            ['from_address' => 'not-an-address'], ['from_address' => ''], ['from_address' => "a@example.com\r\nBcc: x@example.com"],
            ['from_name' => "Acme\nBcc: x"], ['username' => "u\0ser"],
        ] as $bad) {
            $this->put('/settings/mail', $this->form($bad))->assertSessionHasErrors(array_key_first($bad));
        }
        $this->assertFalse(app(MailSettings::class)->configured());

        $this->put('/settings/mail', $this->form(['host' => '[::1]', 'port' => null]))->assertSessionHasNoErrors();
        $this->put('/settings/mail', ['enabled' => false, 'host' => '', 'from_address' => '', 'encryption' => 'starttls', 'remove_password' => true])->assertSessionHasNoErrors();
    }

    public function test_a_stored_password_is_never_sent_to_another_endpoint(): void
    {
        $this->actingAs($this->admin());
        $this->put('/settings/mail', $this->form())->assertSessionHasNoErrors();

        foreach (['host' => 'evil.example.net', 'port' => 25, 'username' => 'someone-else'] as $field => $value) {
            $this->put('/settings/mail', $this->form([$field => $value, 'password' => '']))->assertSessionHasErrors('password');
            $this->post('/settings/mail/test', $this->form([$field => $value, 'password' => '']) + ['recipient' => 'a@example.com'])->assertSessionHasErrors('password');
        }
        $this->put('/settings/mail', $this->form(['encryption' => 'none', 'port' => 587, 'password' => '']))->assertSessionHasErrors('password');
        $this->assertSame('smtp.example.com', app(MailSettings::class)->get()['host']);
        $this->assertSame(self::SECRET, app(MailSettings::class)->credentials()['password']);

        // Same endpoint: the password is kept. Another endpoint: typing one or removing it is accepted.
        $this->put('/settings/mail', $this->form(['password' => '', 'from_name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, app(MailSettings::class)->credentials()['password']);
        $this->put('/settings/mail', $this->form(['host' => 'other.example.net', 'password' => 'new-secret']))->assertSessionHasNoErrors();
        $this->assertSame('new-secret', app(MailSettings::class)->credentials()['password']);
        $this->put('/settings/mail', $this->form(['host' => 'third.example.net', 'password' => '', 'remove_password' => true]))->assertSessionHasNoErrors();
        $this->assertNull(app(MailSettings::class)->credentials()['password']);
    }

    public function test_the_environment_password_is_protected_the_same_way(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.env.test', 'mail.mailers.smtp.username' => 'envuser', 'mail.mailers.smtp.password' => 'env-secret', 'mail.mailers.smtp.port' => 587]);
        $this->actingAs($this->admin());

        $this->put('/settings/mail', $this->form(['host' => 'elsewhere.example.net', 'username' => 'envuser', 'password' => '']))->assertSessionHasErrors('password');

        $this->put('/settings/mail', $this->form(['host' => 'mail.env.test', 'username' => 'envuser', 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('env-secret', app(MailSettings::class)->credentials()['password']);
    }

    public function test_reset_returns_to_the_environment(): void
    {
        $this->actingAs($this->admin());
        $this->put('/settings/mail', $this->form());
        $this->assertTrue(app(MailSettings::class)->configured());

        $this->delete('/settings/mail')->assertRedirect('/settings/mail');

        $this->assertFalse(app(MailSettings::class)->configured());
        $this->get('/settings/mail')->assertInertia(fn (Assert $page) => $page->where('mail.state', 'none')->where('mail.host', '127.0.0.1'));
    }

    public function test_the_test_message_uses_typed_values_shows_only_a_category_and_is_not_saved(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $this->post('/settings/mail/test', $this->form() + ['recipient' => 'ops@example.com'])->assertRedirect('/settings/mail');

        Mail::assertSent(InstanceTestMail::class, fn (InstanceTestMail $mail): bool => $mail->hasTo('ops@example.com'));
        config(['mail.default' => 'log']); // the test applied the typed values to this process only
        $this->get('/settings/mail')->assertInertia(fn (Assert $page) => $page->where('testResult', ['category' => 'sent', 'to' => 'ops@example.com'])->where('mail.state', 'none'));
        $this->get('/settings/mail')->assertInertia(fn (Assert $page) => $page->where('testResult', null));
    }

    public function test_the_test_message_validates_the_recipient_and_is_throttled_to_five_a_minute(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());
        $this->post('/settings/mail/test', $this->form() + ['recipient' => 'nope'])->assertSessionHasErrors('recipient');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/settings/mail/test', $this->form() + ['recipient' => 'ops@example.com'])->assertRedirect('/settings/mail');
        }
        $this->post('/settings/mail/test', $this->form() + ['recipient' => 'ops@example.com'])->assertStatus(429);
    }
}
