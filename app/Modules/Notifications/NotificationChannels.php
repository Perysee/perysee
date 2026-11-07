<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Mail\InstanceMailer;
use App\Modules\Contracts\NotificationChannel;
use App\Modules\ModuleFailures;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The notification channels available on this instance: the Core's e-mail channel ("core.mail") and those modules
 * declare. A key is "<module id>.<name>", so a module cannot take another's key, and a key is declared once.
 *
 * Sending never throws: a channel that fails costs the module that owns it a failure (see {@see ModuleFailures}),
 * and secrets are removed from the message before it is logged.
 *
 * @api Modules declare channels with {@see ModuleProvider::notificationChannel()} and send with {@see send()}.
 */
final class NotificationChannels
{
    public const CORE = 'core';

    private const KEY_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\.[a-z0-9][a-z0-9._-]{0,39}$/D';

    /** @var array<string, array{module: string, channel: NotificationChannel|class-string<NotificationChannel>}> */
    private array $channels;

    public function __construct()
    {
        $this->channels = ['core.mail' => ['module' => self::CORE, 'channel' => MailChannel::class]];
    }

    /**
     * @param  NotificationChannel|class-string<NotificationChannel>  $channel
     */
    public function register(string $module, string $key, NotificationChannel|string $channel): void
    {
        if (! preg_match(self::KEY_PATTERN, $key) || ! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException("Notification channel key \"{$key}\" must be \"{$module}.<name>\" (letters, digits, dashes, dots, underscores).");
        }
        if (isset($this->channels[$key])) {
            throw new InvalidArgumentException("Notification channel \"{$key}\" is already declared.");
        }
        if (is_string($channel) && ! is_subclass_of($channel, NotificationChannel::class)) {
            throw new InvalidArgumentException("\"{$channel}\" does not implement the NotificationChannel contract.");
        }
        $this->channels[$key] = ['module' => $module, 'channel' => $channel];
    }

    /**
     * The channels that can be used now (the owner is enabled), with their plain-text label and whether they are configured.
     *
     * @return list<array{key: string, module: string, label: string, configured: bool}>
     */
    public function available(): array
    {
        $list = [];
        foreach ($this->channels as $key => $entry) {
            if (! $this->usable($entry['module'])) {
                continue;
            }
            try {
                $channel = $this->resolve($entry['channel']);
                $list[] = ['key' => $key, 'module' => $entry['module'], 'label' => $this->plain($channel->label(), 60), 'configured' => $channel->configured()];
            } catch (Throwable $exception) {
                $this->failed($key, $entry['module'], $exception, 'label');
            }
        }

        return $list;
    }

    /**
     * Sends one notification. False when the channel is unknown, its module is disabled, it is not configured or it failed.
     */
    public function send(string $key, string $target, Notification $notification): bool
    {
        $entry = $this->channels[$key] ?? null;
        if ($entry === null || ! $this->usable($entry['module'])) {
            return false;
        }
        try {
            $channel = $this->resolve($entry['channel']);
            if (! $channel->configured()) {
                return false;
            }
            $channel->send($notification, trim(preg_replace('/\p{Cc}+/u', ' ', mb_substr($target, 0, 320)) ?? ''));

            return true;
        } catch (Throwable $exception) {
            $this->failed($key, $entry['module'], $exception, 'send');

            return false;
        }
    }

    private function usable(string $module): bool
    {
        return $module === self::CORE || (app(ModuleRegistry::class)->all()[$module]->enabled ?? false);
    }

    /**
     * @param  NotificationChannel|class-string<NotificationChannel>  $channel
     */
    private function resolve(NotificationChannel|string $channel): NotificationChannel
    {
        return is_string($channel) ? app($channel) : $channel;
    }

    private function failed(string $key, string $module, Throwable $exception, string $context): void
    {
        // A message can quote a secret (a webhook address, a token): only the cleaned text is logged or kept.
        $clean = new RuntimeException(InstanceMailer::scrub($exception->getMessage()));
        Log::warning("Notification channel {$key} failed ({$context}): ".class_basename($exception).': '.$clean->getMessage());
        if ($module !== self::CORE) {
            app(ModuleFailures::class)->report($module, $clean, 'notification channel '.$key);
        }
    }

    private function plain(string $text, int $limit): string
    {
        return trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '', 0, $limit));
    }
}
