<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Mail\InstanceMailer;
use App\Mail\ModuleNotificationMail;
use App\Modules\Contracts\NotificationChannel;
use InvalidArgumentException;

/**
 * The Core's own channel, "core.mail": a plain-text message through the instance mail settings (ADR 003). The target is an e-mail address.
 */
final class MailChannel implements NotificationChannel
{
    public function __construct(private readonly InstanceMailer $mailer) {}

    public function label(): string
    {
        return 'E-mail';
    }

    public function configured(): bool
    {
        return $this->mailer->configured();
    }

    public function send(Notification $notification, string $target): void
    {
        if (filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The target of the e-mail channel must be an e-mail address.');
        }
        $this->mailer->send($target, new ModuleNotificationMail($notification, (string) config('app.name')));
    }
}
