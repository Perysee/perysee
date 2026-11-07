<?php

namespace App\Mail;

use App\Modules\Notifications\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain-text message that a module asked the e-mail channel to send. Sent synchronously, like every other message. */
class ModuleNotificationMail extends Mailable
{
    public function __construct(public Notification $notification, public string $appName) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->notification->level) {
            'critical' => '[Critical] ',
            'warning' => '[Warning] ',
            default => '',
        };

        return new Envelope(subject: $prefix.($this->notification->subject !== '' ? $this->notification->subject : 'Notification from '.$this->appName));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.module-notification');
    }
}
