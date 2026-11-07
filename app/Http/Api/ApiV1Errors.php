<?php

namespace App\Http\Api;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The one shape of an error of the read API: {"error": {"code", "message", "details"}}. Nothing about the
 * failure itself (SQL, file, trace, payload) ever reaches the client, only a code and a fixed message.
 */
class ApiV1Errors
{
    public const VERSION = '1';

    /** Headers carried by every response of the API, successful or not. */
    public static function standardHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'X-Perysee-Api-Version' => self::VERSION,
        ];
    }

    public static function render(Throwable $exception): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = match (true) {
            $exception instanceof ApiError => [$exception->getStatusCode(), $exception->errorCode, $exception->getMessage(), $exception->details, $exception->getHeaders()],
            $exception instanceof ValidationException => [422, 'validation_failed', 'The request is not valid.', ['errors' => $exception->errors()], []],
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => [404, 'not_found', 'Not found.', [], []],
            $exception instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'This endpoint is read-only: use GET.', [], $exception->getHeaders()],
            $exception instanceof HttpException && $exception->getStatusCode() === 429 => [429, 'rate_limited', 'Too many requests.', [], $exception->getHeaders()],
            $exception instanceof HttpException && $exception->getStatusCode() === 503 => [503, 'unavailable', 'The service is temporarily unavailable.', [], $exception->getHeaders()],
            $exception instanceof HttpException => [$exception->getStatusCode(), 'error', 'The request could not be served.', [], $exception->getHeaders()],
            default => [500, 'server_error', 'Something went wrong.', [], []],
        };

        return self::error($status, $code, $message, $details, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status, $headers + self::standardHeaders());
    }

    public static function decorate(Response $response): Response
    {
        foreach (self::standardHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
