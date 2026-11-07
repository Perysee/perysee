<?php

namespace App\Mail;

use App\Analytics\InstanceHealth;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The SMTP server of the instance, managed by an administrator. It lives in the instance state (`analytics_state`,
 * key `mail_settings`) like the retention settings, so no migration is needed. What an administrator saved wins;
 * `.env` (`MAIL_*`) stays the fallback so existing installations keep working. The password is encrypted at rest
 * and is never returned by {@see get()}.
 */
class MailSettings
{
    public const KEY = 'mail_settings';

    public const TIMEOUT = 10;

    public const ENCRYPTIONS = ['none', 'starttls', 'tls'];

    private const DEFAULT_PORTS = ['none' => 25, 'starttls' => 587, 'tls' => 465];

    public function __construct(private InstanceHealth $state) {}

    public static function defaultPort(string $encryption): int
    {
        return self::DEFAULT_PORTS[$encryption] ?? 587;
    }

    /** Whether the environment, not the administrator, provides a real mailer. */
    public static function environmentMailer(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** The non-secret view sent to the administration screen. */
    public function get(): array
    {
        $saved = $this->saved();
        $values = $this->reference();
        $source = [];
        foreach (['host', 'port', 'encryption', 'username', 'from_address', 'from_name'] as $field) {
            $filled = ($values[$field] ?? '') !== '';
            $source[$field] = match (true) {
                $saved !== null => $filled && ($saved[$field] ?? '') !== '' ? 'saved' : 'default',
                self::environmentMailer() && $filled => 'environment',
                default => 'default',
            };
        }

        return [
            'state' => $this->configured() ? 'saved' : (self::environmentMailer() ? 'environment' : 'none'),
            'saved' => $saved !== null,
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'host' => $values['host'],
            'port' => $values['port'],
            'encryption' => $values['encryption'],
            'username' => $values['username'],
            'password_set' => ($values['password'] ?? null) !== null && $values['password'] !== '',
            'from_address' => $values['from_address'],
            'from_name' => $values['from_name'],
            'timeout' => self::TIMEOUT,
            'source' => $source,
        ];
    }

    /** Saved settings that are switched on and complete. */
    public function configured(): bool
    {
        $saved = $this->saved();

        return $saved !== null && ($saved['enabled'] ?? false) === true
            && ($saved['host'] ?? '') !== '' && (int) ($saved['port'] ?? 0) > 0 && ($saved['from_address'] ?? '') !== '';
    }

    /**
     * What a send would use, password included. For internal use only: never send it to the browser or a log.
     *
     * @return array{host: string, port: int, encryption: string, username: ?string, password: ?string, from_address: string, from_name: string}
     */
    public function credentials(): array
    {
        $saved = $this->saved();
        if ($saved === null) {
            return $this->environment();
        }

        return $this->normalise($saved) + ['password' => $this->decrypt($saved['password'] ?? null)];
    }

    /**
     * The settings a form is compared with: the saved ones, or the environment when nothing was saved. This is the
     * only place a password may be inherited from, and only for the same endpoint.
     */
    public function reference(): array
    {
        return $this->saved() === null ? $this->environment() : $this->credentials();
    }

    /**
     * Turns form input into complete values. An empty password keeps the reference one, unless "remove_password" is set.
     * The caller must have refused an empty password for another endpoint ({@see passwordWouldTravel()}).
     */
    public function build(array $input): array
    {
        $reference = $this->reference();
        $encryption = in_array($input['encryption'] ?? null, self::ENCRYPTIONS, true) ? $input['encryption'] : 'starttls';
        $port = (int) ($input['port'] ?? 0) ?: self::defaultPort($encryption);
        $typed = (string) ($input['password'] ?? '');
        $password = match (true) {
            $typed !== '' => $typed,
            ! empty($input['remove_password']) => null,
            default => $reference['password'] ?? null,
        };
        $name = trim((string) ($input['from_name'] ?? ''));

        return [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => $port,
            'encryption' => $encryption,
            'username' => ($username = trim((string) ($input['username'] ?? ''))) === '' ? null : $username,
            'password' => $password === '' ? null : $password,
            'from_address' => trim((string) ($input['from_address'] ?? '')),
            'from_name' => $name,
        ];
    }

    /**
     * True when the password of the reference would be sent somewhere else than where it was entered: a different
     * host, port or user, or a downgrade to an unencrypted connection. The form must then ask for it again.
     */
    public function passwordWouldTravel(array $input): bool
    {
        $reference = $this->reference();
        if (($reference['password'] ?? '') === '' || ($input['password'] ?? '') !== '' || ! empty($input['remove_password'])) {
            return false;
        }
        $next = $this->build($input);

        return strtolower($next['host']) !== strtolower((string) $reference['host'])
            || $next['port'] !== (int) $reference['port']
            || (string) $next['username'] !== (string) $reference['username']
            || ($next['encryption'] === 'none' && $reference['encryption'] !== 'none');
    }

    public function save(array $input): void
    {
        $values = $this->build($input);
        $values['password'] = $values['password'] === null ? null : Crypt::encryptString($values['password']);
        $this->state->put(self::KEY, $values);
    }

    /**
     * Points the SMTP mailer at the saved settings, or at `$values` (as built by {@see build()}) to try unsaved ones.
     * It runs when a message is about to leave, never on every request, and forgets any resolved mailer so the
     * change takes effect at once. Without usable saved settings the environment configuration is left alone.
     */
    public function apply(?array $values = null): void
    {
        if ($values === null) {
            if (! $this->configured()) {
                return;
            }
            $values = $this->credentials();
        }
        $name = $values['from_name'] !== '' ? $values['from_name'] : config('app.name');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => $values['encryption'] === 'tls' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.auto_tls' => $values['encryption'] === 'starttls',
            'mail.mailers.smtp.require_tls' => $values['encryption'] === 'starttls',
            'mail.mailers.smtp.host' => $values['host'],
            'mail.mailers.smtp.port' => $values['port'],
            'mail.mailers.smtp.username' => $values['username'],
            'mail.mailers.smtp.password' => $values['password'] ?? null,
            'mail.mailers.smtp.timeout' => self::TIMEOUT,
            'mail.from.address' => $values['from_address'],
            'mail.from.name' => $name,
        ]);
        app('mail.manager')->forgetMailers();
    }

    public function reset(): void
    {
        $this->state->forget(self::KEY);
    }

    private function saved(): ?array
    {
        $saved = $this->state->get(self::KEY);

        return is_array($saved) ? $saved : null;
    }

    private function normalise(array $saved): array
    {
        $encryption = in_array($saved['encryption'] ?? null, self::ENCRYPTIONS, true) ? $saved['encryption'] : 'starttls';

        return [
            'host' => (string) ($saved['host'] ?? ''),
            'port' => (int) ($saved['port'] ?? 0) ?: self::defaultPort($encryption),
            'encryption' => $encryption,
            'username' => ($saved['username'] ?? '') === '' ? null : (string) $saved['username'],
            'from_address' => (string) ($saved['from_address'] ?? ''),
            'from_name' => (string) (($saved['from_name'] ?? '') !== '' ? $saved['from_name'] : config('app.name')),
        ];
    }

    private function environment(): array
    {
        $mailer = (array) config('mail.mailers.smtp');
        $port = (int) ($mailer['port'] ?? 0) ?: 25;
        $scheme = $mailer['scheme'] ?? null;
        $encryption = $scheme === 'smtps' || ($scheme === null && $port === 465) ? 'tls' : 'starttls';

        return [
            'host' => (string) ($mailer['host'] ?? ''),
            'port' => $port,
            'encryption' => $encryption,
            'username' => ($mailer['username'] ?? '') === '' ? null : (string) $mailer['username'],
            'password' => ($mailer['password'] ?? '') === '' ? null : (string) $mailer['password'],
            'from_address' => (string) config('mail.from.address'),
            'from_name' => (string) (config('mail.from.name') ?: config('app.name')),
        ];
    }

    private function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            // The application key changed: the password must be typed again.
            return null;
        }
    }
}
