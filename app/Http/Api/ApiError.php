<?php

namespace App\Http\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A failure of the read API, with the stable machine-readable code clients rely on. */
class ApiError extends HttpException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, public readonly string $errorCode, string $message, public readonly array $details = [], array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }

    public static function invalidToken(): self
    {
        return new self(401, 'invalid_token', 'The API token is missing, malformed, expired or revoked.', [], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function insufficientScope(string $required): self
    {
        return new self(403, 'insufficient_scope', 'This token does not have the scope this request needs.', ['required' => $required]);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }
}
