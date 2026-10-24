<?php

namespace App\Http\Controllers;

use App\Analytics\Privacy\NormalizePath;
use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackingController extends Controller
{
    public function script(Request $request): Response
    {
        $encoding = AcceptHeader::fromString($request->header('Accept-Encoding', ''));
        $gzip = ($encoding->get('gzip') ?? $encoding->get('*'))?->getQuality() > 0;
        $path = public_path('tracker/lueur.v1.js'.($gzip ? '.gz' : ''));
        abort_unless(is_file($path), 503, 'Build the tracking script before deployment.');
        $headers = ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600', 'Vary' => 'Accept-Encoding', 'Access-Control-Allow-Origin' => '*', 'X-Content-Type-Options' => 'nosniff'];
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return response(file_get_contents($path), 200, $headers);
    }

    public function collect(Request $request, NormalizePath $normalize): Response
    {
        abort_unless(app(InstallationState::class)->isLocked(), 503);
        abort_if(strlen($request->getContent()) > 4096, 413);
        $contentType = strtolower(explode(';', $request->header('Content-Type', ''))[0]);
        abort_unless(in_array($contentType, ['application/json', 'text/plain'], true), 415);
        $data = json_decode($request->getContent(), true);
        abort_unless(is_array($data), 422);
        abort_if(array_diff(array_keys($data), ['v', 'site', 'type', 'mode', 'host', 'path', 'session', 'consent']), 422);
        $data = Validator::make($data, [
            'v' => ['required', 'integer', 'in:1'], 'site' => ['required', 'uuid'], 'type' => ['required', 'in:pageview'],
            'mode' => ['required', 'in:pageviews,sessions'], 'host' => ['required', 'string', 'max:253'],
            'path' => ['required', 'string', 'max:512', 'regex:/^\/(?!\/)/'],
            'consent' => ['required', 'boolean'], 'session' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ])->validate();
        try {
            $site = Website::where('public_id', $data['site'])->first();
        } catch (Throwable) {
            return response('', 503);
        }
        abort_unless($site, 404);
        $origin = $request->header('Origin', '');
        $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
        abort_unless(in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true) && $host === strtolower($data['host']) && in_array($host, $site->allowed_domains, true), 403);
        abort_if($site->consent_required && ! $data['consent'], 403);
        abort_unless($site->tracking_mode === $data['mode'], 422);
        abort_if($site->tracking_mode === 'sessions' ? empty($data['session']) : ! empty($data['session']), 422);
        $key = 'collect:'.$site->id.':'.hash_hmac('sha256', $request->ip() ?? '', config('app.key'));
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429);
        RateLimiter::hit($key, 60);
        try {
            DB::table('events')->insert([
                'website_id' => $site->id, 'received_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'event_type' => 1,
                'path' => $normalize($data['path']), 'consent_given' => (bool) $data['consent'],
                'session_key' => $site->tracking_mode === 'sessions' ? hash_hmac('sha256', $site->public_id.':'.$data['session'], config('app.key')) : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Tracking insert failed.', ['exception_type' => get_class($exception)]);

            return response('', 503);
        }

        return response('', 204, ['Access-Control-Allow-Origin' => '*', 'Vary' => 'Origin', 'Cache-Control' => 'no-store']);
    }
}
