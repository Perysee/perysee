<?php

namespace Tests\Support;

/**
 * Laravel's test client does not send the session cookie back, so the session id changes on every request
 * (its data is merged in memory). Tests about the id itself call browser() to behave like a real browser.
 */
trait KeepsSessionCookie
{
    protected function browser(): static
    {
        return $this->withCookie(session()->getName(), session()->getId());
    }
}
