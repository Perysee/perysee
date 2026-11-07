<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null, 'mail.from.address' => 'hello@example.com', 'mail.from.name' => 'Perysee']);
    }

    private function settings(): MailSettings
    {
        return app(MailSettings::class);
    }

    private function valid(array $override = []): array
    {
        return $override + ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password' => self::SECRET, 'from_address' => 'noreply@example.com', 'from_name' => 'Acme Analytics'];
    }

    public function test_nothing_is_saved_until_an_administrator_saves_and_the_environment_is_shown_meanwhile(): void
    {
        $this->assertFalse($this->settings()->configured());
        $view = $this->settings()->get();
        $this->assertSame('none', $view['state']);
        $this->assertSame('127.0.0.1', $view['host']);
        $this->assertSame(2525, $view['port']);
        $this->assertSame('default', $view['source']['host']);
        $this->assertFalse($view['password_set']);
        $this->assertSame(10, $view['timeout']);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.env.test', 'mail.mailers.smtp.password' => 'from-the-environment']);
        $view = $this->settings()->get();
        $this->assertSame('environment', $view['state']);
        $this->assertSame('mail.env.test', $view['host']);
        $this->assertSame('environment', $view['source']['host']);
        $this->assertTrue($view['password_set']);
        $this->assertStringNotContainsString('from-the-environment', json_encode($view));
    }

    public function test_saved_settings_win_over_the_environment_and_the_password_is_encrypted_and_never_returned(): void
    {
        $this->settings()->save($this->valid());

        $this->assertTrue($this->settings()->configured());
        $view = $this->settings()->get();
        $this->assertSame('saved', $view['state']);
        $this->assertSame(['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password_set' => true, 'from_address' => 'noreply@example.com', 'from_name' => 'Acme Analytics'], array_intersect_key($view, array_flip(['enabled', 'host', 'port', 'encryption', 'username', 'password_set', 'from_address', 'from_name'])));
        $this->assertSame('saved', $view['source']['host']);
        $this->assertArrayNotHasKey('password', $view);
        $this->assertStringNotContainsString(self::SECRET, json_encode($view));

        $stored = (string) DB::table('analytics_state')->where('name', 'mail_settings')->value('value');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertSame(self::SECRET, Crypt::decryptString(json_decode($stored, true)['password']));
    }

    public function test_the_port_defaults_from_the_encryption_and_the_from_name_defaults_to_the_application_name(): void
    {
        config(['app.name' => 'Acme Stats']);
        foreach (['none' => 25, 'starttls' => 587, 'tls' => 465] as $encryption => $port) {
            $this->settings()->save($this->valid(['encryption' => $encryption, 'port' => null, 'from_name' => '']));
            $this->assertSame($port, $this->settings()->get()['port']);
        }
        $this->assertSame('Acme Stats', $this->settings()->get()['from_name']);
        $this->assertSame('default', $this->settings()->get()['source']['from_name']);
    }

    public function test_saving_without_a_password_keeps_the_stored_one_and_remove_password_clears_it(): void
    {
        $this->settings()->save($this->valid());
        $this->settings()->save($this->valid(['password' => '', 'from_name' => 'Renamed']));
        $this->assertSame(self::SECRET, $this->settings()->credentials()['password']);

        $this->settings()->save($this->valid(['password' => '', 'remove_password' => true]));
        $this->assertNull($this->settings()->credentials()['password']);
        $this->assertFalse($this->settings()->get()['password_set']);
    }

    public function test_incomplete_or_disabled_settings_are_not_configured_and_reset_returns_to_the_environment(): void
    {
        $this->settings()->save($this->valid(['enabled' => false]));
        $this->assertFalse($this->settings()->configured());
        $this->assertSame('none', $this->settings()->get()['state']);
        $this->assertSame('smtp.example.com', $this->settings()->get()['host']);

        $this->settings()->save($this->valid(['host' => '']));
        $this->assertFalse($this->settings()->configured());

        $this->settings()->save($this->valid());
        $this->assertTrue($this->settings()->configured());
        $this->settings()->reset();
        $this->assertFalse($this->settings()->configured());
        $this->assertSame('127.0.0.1', $this->settings()->get()['host']);
        $this->assertNull(app(InstanceHealth::class)->get('mail_settings'));
    }

    public function test_apply_configures_the_smtp_mailer_lazily_and_purges_the_resolved_one(): void
    {
        $this->settings()->save($this->valid());
        $this->assertSame('log', config('mail.default'), 'Saving does not touch the running configuration.');

        app('mail.manager')->mailer('log');
        $this->settings()->apply();

        $this->assertSame('smtp', config('mail.default'));
        $smtp = config('mail.mailers.smtp');
        $this->assertSame('smtp.example.com', $smtp['host']);
        $this->assertSame(587, $smtp['port']);
        $this->assertSame('mailer', $smtp['username']);
        $this->assertSame(self::SECRET, $smtp['password']);
        $this->assertSame('smtp', $smtp['scheme']);
        $this->assertTrue($smtp['require_tls']);
        $this->assertSame(10, $smtp['timeout']);
        $this->assertNull($smtp['url']);
        $this->assertSame(['address' => 'noreply@example.com', 'name' => 'Acme Analytics'], config('mail.from'));
        $this->assertNull($this->readMailers(), 'The resolved mailers are forgotten so the change takes effect at once.');
        $this->assertSame('smtps', $this->schemeFor('tls'));
        $this->assertSame('smtp', $this->schemeFor('none'));
        $this->assertFalse(config('mail.mailers.smtp.auto_tls'), 'Without encryption the transport must not upgrade on its own.');
        $this->assertFalse(config('mail.mailers.smtp.require_tls'));
    }

    public function test_apply_does_nothing_when_the_saved_settings_are_not_in_use(): void
    {
        $this->settings()->apply();
        $this->assertSame('log', config('mail.default'));

        $this->settings()->save($this->valid(['enabled' => false]));
        $this->settings()->apply();
        $this->assertSame('log', config('mail.default'));
        $this->assertSame('127.0.0.1', config('mail.mailers.smtp.host'));
    }

    private function schemeFor(string $encryption): string
    {
        $this->settings()->apply($this->settings()->build($this->valid(['encryption' => $encryption, 'port' => null])));

        return config('mail.mailers.smtp.scheme');
    }

    private function readMailers(): ?array
    {
        $manager = app('mail.manager');
        $property = new \ReflectionProperty($manager, 'mailers');

        return $property->getValue($manager) ?: null;
    }

    public function test_an_unreadable_stored_password_is_treated_as_missing_instead_of_failing(): void
    {
        app(InstanceHealth::class)->put('mail_settings', ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'password' => 'not-a-valid-payload', 'from_address' => 'a@example.com', 'from_name' => '']);
        $this->assertNull($this->settings()->credentials()['password']);
        $this->assertTrue($this->settings()->configured());
    }
}
