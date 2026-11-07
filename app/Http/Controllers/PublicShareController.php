<?php

namespace App\Http\Controllers;

use App\Analytics\Reporting\ReportPresenter;
use App\Analytics\Reporting\Reports;
use App\Models\ShareLink;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, read-only view of a report. The site and the sections come only from the link; the request can
 * choose a period, a granularity and one dimension among those the link allows, and nothing else.
 */
class PublicShareController extends Controller
{
    public function show(Request $request, Reports $reports, ReportPresenter $presenter): Response
    {
        [$link, $site] = $this->context($request);
        if (! $request->attributes->get('share_unlocked')) {
            return Inertia::render('Share/Unlock');
        }
        $period = $this->period($request, $site, $reports);
        $dimension = $this->dimension($request, $link);
        $this->recordUse($link);
        $overview = $link->allows('overview');

        return Inertia::render('Share/Show', [
            'site' => ['name' => $site->name, 'domain' => $site->domain, 'timezone' => $site->timezone],
            'sections' => $link->sections,
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'granularity' => $period['granularity']],
            'today' => now($site->timezone)->toDateString(),
            'report' => $overview ? $presenter->overview($site, $period) : null,
            'series' => $overview ? $presenter->series($site, $period) : [],
            'dimensionOptions' => $link->dimensions(),
            'dimension' => $dimension,
            'dimensionReport' => $dimension !== null ? $presenter->dimensionReport($site, $period, $dimension) : null,
        ]);
    }

    public function api(Request $request, string $token, string $report, Reports $reports, ReportPresenter $presenter): JsonResponse
    {
        [$link, $site] = $this->context($request);
        abort_unless($request->attributes->get('share_unlocked'), 404);
        $period = $this->period($request, $site, $reports);
        if ($report === 'dimensions') {
            $dimension = $this->dimension($request, $link);
            abort_if($dimension === null, 404);
            $data = $presenter->dimensionReport($site, $period, $dimension);
        } else {
            abort_unless($link->allows('overview'), 404);
            $data = $report === 'overview' ? $presenter->overview($site, $period) : ['data' => $presenter->series($site, $period)];
        }

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    public function unlock(Request $request, string $token, Coordination $coordination): RedirectResponse
    {
        [$link] = $this->context($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:128']]);
        // Every attempt counts, per link and per client address; a good password forgives the link's count.
        $linkKey = 'share:password:link:'.$link->id;
        if (! $coordination->throttle($linkKey, 5, 900) || ! $coordination->throttle('share:password:ip:'.sha1((string) $request->ip()), 20, 3600)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Try again later.']);
        }
        if ($link->password_hash === null || ! Hash::check($data['password'], $link->password_hash)) {
            throw ValidationException::withMessages(['password' => 'The password is not correct.']);
        }
        RateLimiter::clear($linkKey);
        $request->session()->put('share_unlocked.'.$link->id, $link->secret_version);

        // Back to the clean address, so the token is never part of a form submission result.
        return redirect()->route('share.show', $token);
    }

    /** Stamps the link at most once an hour, in one statement, so a busy link costs almost no writes. */
    private function recordUse(ShareLink $link): void
    {
        ShareLink::whereKey($link->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subHour()))
            ->update(['last_used_at' => now(), 'use_count' => DB::raw('use_count + 1')]);
    }

    /** @return array{0: ShareLink, 1: Website} */
    private function context(Request $request): array
    {
        return [$request->attributes->get('share_link'), $request->attributes->get('share_site')];
    }

    /** Only these query values are read; filters, values and any site identifier are dropped. */
    private function period(Request $request, Website $site, Reports $reports): array
    {
        return $reports->period(Request::create('/', 'GET', $request->only(['period', 'from', 'to', 'granularity'])), $site);
    }

    private function dimension(Request $request, ShareLink $link): ?string
    {
        $allowed = $link->dimensions();
        $dimension = $request->query('dimension');
        if ($dimension === null) {
            return $link->allows('overview') ? null : ($allowed[0] ?? null);
        }
        abort_unless(is_string($dimension) && in_array($dimension, $allowed, true), 404);

        return $dimension;
    }
}
