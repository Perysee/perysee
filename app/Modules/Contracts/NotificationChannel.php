<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Modules\ModuleApi;
use App\Modules\ModuleProvider;
use App\Modules\Notifications\Notification;

/**
 * A way to tell someone about something (e-mail, a chat, a webhook), declared by a module with
 * {@see ModuleProvider::notificationChannel()}.
 *
 * @api Part of module API 1, available from the Perysee release named by {@see ModuleApi::DOMAIN_EVENTS_SINCE}.
 */
interface NotificationChannel
{
    /** A short name for administrators, in plain text. */
    public function label(): string;

    /** Whether the channel can send right now (settings complete, service reachable by configuration). */
    public function configured(): bool;

    /**
     * Sends one notification. $target is whatever the channel understands (an address, a channel name).
     * Throwing is allowed: the Core catches it, removes secrets from the message and counts the failure against the module.
     */
    public function send(Notification $notification, string $target): void;
}
