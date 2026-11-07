<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * The single entry point for every e-mail the instance sends (invitations today, reports and alerts later).
 * It applies the administrator's SMTP settings just before sending, sends synchronously (no worker is assumed on
 * shared hosting) and never lets a server answer or a credential reach the interface or the logs.
 */
class InstanceMailer
{
    public function __construct(private MailSettings $settings) {}

    /** Whether a message can really leave: saved settings that are on and complete, or a mailer other than log/array in `.env`. */
    public function configured(): bool
    {
        return $this->settings->configured() || MailSettings::environmentMailer();
    }

    /** @throws MailNotSent */
    public function send(string $to, Mailable $mail): void
    {
        $this->settings->apply();
        $this->deliver($to, $mail);
    }

    /**
     * Sends a test message and says what went wrong in one word. `$values` are unsaved form values (as built by
     * {@see MailSettings::build()}); without them the saved settings, or the environment, are used.
     *
     * @return array{category: string} sent, connection, authentication, sender_rejected, other or not_configured
     */
    public function test(string $to, ?array $values = null): array
    {
        if ($values === null && ! $this->configured()) {
            return ['category' => 'not_configured'];
        }
        $values === null ? $this->settings->apply() : $this->settings->apply($values);
        try {
            $this->deliver($to, new InstanceTestMail(config('app.name')));
        } catch (MailNotSent $failure) {
            return ['category' => $failure->category];
        }

        return ['category' => 'sent'];
    }

    private function deliver(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $exception) {
            $text = self::scrub($exception->getMessage(), [config('mail.mailers.smtp.password'), config('mail.mailers.smtp.username')]);
            report(new \RuntimeException('Mail delivery failed ('.class_basename($exception).'): '.$text));

            throw new MailNotSent(self::categorise($exception->getMessage()));
        }
    }

    public static function categorise(string $message): string
    {
        return match (true) {
            (bool) preg_match('/could not be established|timed out|connection (refused|reset|closed)|closed unexpectedly|unable to connect|getaddrinfo|name or service|network is unreachable|STARTTLS|TLS is required/i', $message) => 'connection',
            (bool) preg_match('/failed to authenticate|code "5(30|34|35|38)"|code "454"|authentication (failed|required)/i', $message) => 'authentication',
            (bool) preg_match('/code "553"|code "5\d\d".*(sender|from address|relay|not owned|not allowed|spoof)/i', $message) => 'sender_rejected',
            default => 'other',
        };
    }

    /**
     * Removes what looks like a credential from an exception text before it is logged: the configured secrets,
     * "password=…" style pairs and long opaque tokens (base64, API keys).
     *
     * @param  list<mixed>  $secrets
     */
    public static function scrub(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 3) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $text = preg_replace('/\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key|auth\w*)(["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2[redacted]', $text) ?? '';

        return preg_replace('/[A-Za-z0-9+\/_=-]{20,}/', '[redacted]', $text) ?? '';
    }
}
