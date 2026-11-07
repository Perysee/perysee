<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The message of the "Send a test e-mail" button. Sent synchronously. */
class InstanceTestMail extends Mailable
{
    public function __construct(public string $appName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test e-mail from '.$this->appName);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.instance-test');
    }
}
