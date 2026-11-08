<?php

namespace App\Http\Controllers;

use App\Analytics\Ingestion\CollectionRejected;
use App\Analytics\Ingestion\Dimensions;
use App\Analytics\Ingestion\EventValues;
use App\Analytics\Ingestion\Geolocation;
use App\Analytics\Ingestion\ProbeToken;
use App\Analytics\Ingestion\RejectionCause;
use App\Analytics\Ingestion\RejectionLog;
use App\Analytics\InstanceHealth;
use App\Analytics\Privacy\NormalizePath;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        // Installation diagnostics: a refused request is filed by cause in the catch below, after the rollback.
        $values = app(EventValues::class);
        $prepared = null;
        $duplicate = false;
        try {
            CollectionRejected::if(strlen($request->getContent()) > 4096, RejectionCause::InvalidPayload, 413);
            $contentType = strtolower(explode(';', $request->header('Content-Type', ''))[0]);
            CollectionRejected::unless(in_array($contentType, ['application/json', 'text/plain'], true), RejectionCause::InvalidPayload, 415);
            $data = json_decode($request->getContent(), true);
            CollectionRejected::unless(is_array($data), RejectionCause::InvalidPayload, 422);
            $extra = $values->take($data);
            $siteKey = is_string($data['site'] ?? null) ? $data['site'] : null;
            CollectionRejected::if(array_diff(array_keys($data), ['v', 'site', 'type', 'mode', 'host', 'path', 'session', 'consent', 'event_name', 'ref', 'utm_source', 'utm_medium', 'utm_campaign', 'device_hint']), RejectionCause::InvalidPayload, 422, siteKey: $siteKey);
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
            // Installation check: a signed "Test now" request proves the collector and the database without storing an event.
            if ($request->headers->has(ProbeToken::HEADER) && app(ProbeToken::class)->valid($request->header(ProbeToken::HEADER), $data['site'])) {
                $probe = $this->probe($request, $data['site']);
                if ($probe) {
                    return $probe;
                }
            }
            $prepared = $values->prepare($extra, $data);
            DB::transaction(function () use ($data, $request, $normalize, $values, $prepared, &$duplicate): void {
                $site = Website::where('public_id', $data['site'])->sharedLock()->first();
                CollectionRejected::unless($site && ! $site->deleting_at, RejectionCause::UnknownSite, 404, origin: $request->header('Origin'));
                $origin = $request->header('Origin', '');
                $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
                CollectionRejected::unless(in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true) && $host === strtolower($data['host']) && in_array($host, $site->allowed_domains, true), RejectionCause::DomainNotAllowed, 403, $site, origin: $origin);
                CollectionRejected::if($site->consent_required && ! $data['consent'], RejectionCause::ConsentMissing, 403, $site);
                CollectionRejected::unless($site->tracking_mode === $data['mode'], RejectionCause::ModeMismatch, 422, $site);
                CollectionRejected::if($site->tracking_mode === 'sessions' ? empty($data['session']) : ! empty($data['session']), RejectionCause::ModeMismatch, 422, $site);
                $path = $normalize($data['path']);
                foreach ($site->excluded_paths ?? [] as $rule) {
                    CollectionRejected::if(str_ends_with($rule, '*') ? str_starts_with($path, substr($rule, 0, -1)) : $path === $rule, RejectionCause::PathExcluded, 403, $site);
                }
                $key = 'collect:'.$site->id.':'.hash_hmac('sha256', $request->ip() ?? '', config('app.key'));
                CollectionRejected::unless(app(Coordination::class)->throttle($key, (int) config('perysee.collect.rate_limit.max_attempts'), (int) config('perysee.collect.rate_limit.decay_seconds')), RejectionCause::Quota, 429, $site);
                $received = now('UTC')->format('Y-m-d H:i:s.v');
                if (! $site->first_collected_at) {
                    DB::table('websites')->where('id', $site->id)->whereNull('first_collected_at')->update(['first_collected_at' => $received]);
                }
                if (! $values->claim($prepared, $site, $data['event_name'] ?? '', substr($received, 0, 19))) {
                    $duplicate = true;

                    return;
                }
                DB::table('events')->insert(array_merge([
                    'website_id' => $site->id, 'received_at' => $received, 'event_type' => $data['type'] === 'event' ? 2 : 1,
                    'path' => $path, 'consent_given' => (bool) $data['consent'],
                    'session_key' => $site->tracking_mode === 'sessions' ? hash_hmac('sha256', $site->public_id.':'.$data['session'], config('app.key')) : null,
                ], app(Dimensions::class)->values($data, $request, $site), app(Geolocation::class)->lookup($request->ip() ?? ''), $prepared['columns']));
            }, 3);
        } catch (ValidationException $exception) {
            app(InstanceHealth::class)->failure('collection_rejections');
            app(RejectionLog::class)->write(RejectionCause::InvalidPayload, null, $siteKey ?? null);
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            app(InstanceHealth::class)->failure('collection_rejections');
            if ($exception instanceof CollectionRejected) {
                app(RejectionLog::class)->record($exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            app(InstanceHealth::class)->failure('collection_failures');
            Log::warning('Tracking insert failed.', ['exception_type' => get_class($exception)]);

            return response('', 503);
        }

        $headers = ['Access-Control-Allow-Origin' => '*', 'Vary' => 'Origin', 'Cache-Control' => 'no-store'];
        // Event values: what was dropped or masked, and an order that was already counted. The event itself is kept.
        if (! $duplicate && ($truncated = $values->header($prepared))) {
            app(InstanceHealth::class)->failure('property_truncations');
            $headers += ['X-Perysee-Truncated' => $truncated, 'Access-Control-Expose-Headers' => 'X-Perysee-Truncated'];
        }
        if ($duplicate) {
            $headers += ['X-Perysee-Duplicate' => 'order', 'Access-Control-Expose-Headers' => 'X-Perysee-Duplicate'];
        }

        return response('', 204, $headers);
    }

    /** Answers a valid installation test; null when the site does not exist, so that the usual path refuses it. */
    private function probe(Request $request, string $publicId): ?Response
    {
        $site = Website::where('public_id', $publicId)->first();
        if (! $site || $site->deleting_at) {
            return null;
        }
        CollectionRejected::unless(app(Coordination::class)->throttle('probe:'.$site->id, 12, 60), RejectionCause::Quota, 429, $site);
        app(RejectionLog::class)->probe($site->id);

        return response('', 204, ['Access-Control-Allow-Origin' => '*', 'Access-Control-Expose-Headers' => ProbeToken::HEADER, ProbeToken::HEADER => $request->header(ProbeToken::HEADER), 'Vary' => 'Origin', 'Cache-Control' => 'no-store']);
    }

    /** Lets the administration, when served from another origin than the script, send its signed test. */
    public function preflight(): Response
    {
        return response('', 204, ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type, '.ProbeToken::HEADER, 'Access-Control-Max-Age' => '600', 'Vary' => 'Origin', 'Cache-Control' => 'no-store']);
    }
}
