<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously (no queue worker is assumed on shared hosting). */
class SiteInvitationMail extends Mailable
{
    public function __construct(
        public string $url,
        public string $siteName,
        public string $role,
        public ?string $inviter,
        public string $expiresOn,
        public string $appName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to '.$this->siteName.' on '.$this->appName);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.site-invitation');
    }
}
