<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\ModuleApi;

/**
 * What a notification channel is asked to send: plain text only. Control characters are removed and the lengths are
 * capped on construction, so a channel never has to defend itself against a module's text.
 *
 * @api Part of module API 1, available from the Perysee release named by {@see ModuleApi::DOMAIN_EVENTS_SINCE}.
 */
final readonly class Notification
{
    public const LEVELS = ['info', 'warning', 'critical'];

    public const SUBJECT_LIMIT = 150;

    public const BODY_LIMIT = 2000;

    public string $subject;

    public string $body;

    public string $level;

    /** An address inside this instance, such as "/health", or null. */
    public ?string $url;

    public function __construct(string $subject, string $body, string $level = 'info', ?string $url = null)
    {
        $this->subject = trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $subject) ?? '', 0, self::SUBJECT_LIMIT));
        // Line breaks stay in the body; every other control character goes.
        $clean = preg_replace('/[^\P{Cc}\n]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $body)) ?? '';
        $this->body = trim(mb_substr($clean, 0, self::BODY_LIMIT));
        $this->level = in_array($level, self::LEVELS, true) ? $level : 'info';
        $this->url = $url !== null && preg_match('#^/(?![/\\\\])[^\s"\'<>\\\\]*$#D', $url) ? $url : null;
    }
}
