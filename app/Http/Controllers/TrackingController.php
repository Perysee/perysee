<?php

namespace App\Http\Controllers;

use App\Analytics\Ingestion\Dimensions;
use App\Analytics\Ingestion\Geolocation;
use App\Analytics\InstanceHealth;
use App\Analytics\Privacy\NormalizePath;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        abort_if(array_diff(array_keys($data), ['v', 'site', 'type', 'mode', 'host', 'path', 'session', 'consent', 'event_name', 'ref', 'utm_source', 'utm_medium', 'utm_campaign', 'device_hint']), 422);
        $data = Validator::make($data, [
            'v' => ['required', 'integer', 'in:1'], 'site' => ['required', 'uuid'], 'type' => ['required', 'in:pageview,event'],
            'mode' => ['required', 'in:pageviews,sessions'], 'host' => ['required', 'string', 'max:253'],
            'path' => ['required', 'string', 'max:512', 'regex:/^\/(?!\/)/'],
            'event_name' => ['required_if:type,event', 'prohibited_if:type,pageview', 'string', 'max:64', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]*$/D'],
            'device_hint' => ['sometimes', 'nullable', 'in:tablet'],
            'ref' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'utm_source' => ['sometimes', 'nullable', 'string', 'max:100'], 'utm_medium' => ['sometimes', 'nullable', 'string', 'max:100'], 'utm_campaign' => ['sometimes', 'nullable', 'string', 'max:100'],
            'consent' => ['required', 'boolean'], 'session' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ])->validate();
        try {
            DB::transaction(function () use ($data, $request, $normalize): void {
                $site = Website::where('public_id', $data['site'])->sharedLock()->first();
                abort_unless($site && ! $site->deleting_at, 404);
                $origin = $request->header('Origin', '');
                $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
                abort_unless(in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true) && $host === strtolower($data['host']) && in_array($host, $site->allowed_domains, true), 403);
                abort_if($site->consent_required && ! $data['consent'], 403);
                abort_unless($site->tracking_mode === $data['mode'], 422);
                abort_if($site->tracking_mode === 'sessions' ? empty($data['session']) : ! empty($data['session']), 422);
                $path = $normalize($data['path']);
                foreach ($site->excluded_paths ?? [] as $rule) {
                    abort_if(str_ends_with($rule, '*') ? str_starts_with($path, substr($rule, 0, -1)) : $path === $rule, 403);
                }
                $key = 'collect:'.$site->id.':'.hash_hmac('sha256', $request->ip() ?? '', config('app.key'));
                abort_unless(app(Coordination::class)->throttle($key, 120, 60), 429);
                $received = now('UTC')->format('Y-m-d H:i:s.v');
                if (! $site->first_collected_at) {
                    DB::table('websites')->where('id', $site->id)->whereNull('first_collected_at')->update(['first_collected_at' => $received]);
                }
                DB::table('events')->insert(array_merge([
                    'website_id' => $site->id, 'received_at' => $received, 'event_type' => $data['type'] === 'event' ? 2 : 1,
                    'path' => $path, 'consent_given' => (bool) $data['consent'],
                    'session_key' => $site->tracking_mode === 'sessions' ? hash_hmac('sha256', $site->public_id.':'.$data['session'], config('app.key')) : null,
                ], app(Dimensions::class)->values($data, $request, $site), app(Geolocation::class)->lookup($request->ip() ?? '')));
            }, 3);
        } catch (HttpExceptionInterface $exception) {
            app(InstanceHealth::class)->failure('collection_rejections');
            throw $exception;
        } catch (Throwable $exception) {
            app(InstanceHealth::class)->failure('collection_failures');
            Log::warning('Tracking insert failed.', ['exception_type' => get_class($exception)]);

            return response('', 503);
        }

        return response('', 204, ['Access-Control-Allow-Origin' => '*', 'Vary' => 'Origin', 'Cache-Control' => 'no-store']);
    }
}
