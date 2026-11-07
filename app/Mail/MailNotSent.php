<?php

namespace App\Mail;

/**
 * A message could not be handed to the mail server. It carries no server text and no previous exception on purpose:
 * the details were reported once, stripped of anything that looks like a credential.
 */
class MailNotSent extends \RuntimeException
{
    public function __construct(public readonly string $category = 'other')
    {
        parent::__construct('The e-mail could not be sent.');
    }
}
