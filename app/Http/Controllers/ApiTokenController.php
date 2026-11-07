<?php

namespace App\Http\Controllers;

use App\Analytics\Reporting\ReportSections;
use App\Models\ApiToken;
use App\Models\Website;
use App\Support\SecretPage;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Creation, listing and revocation of the read API tokens of one site. Owners only, never on a demonstration site. */
class ApiTokenController extends Controller
{
    private function authorizeTokens(Website $website): void
    {
        Gate::authorize('manageApiTokens', $website);
        abort_if($website->deleting_at || $website->is_demo, 404);
    }

    /** @return array{default: int, min: int, max: int} */
    private function rates(): array
    {
        $max = max((int) config('perysee.api.max_rate', 300), (int) config('perysee.api.min_rate', 10));

        return ['default' => min((int) config('perysee.api.default_rate', 60), $max), 'min' => (int) config('perysee.api.min_rate', 10), 'max' => $max];
    }

    public function index(Request $request, Website $website)
    {
        $this->authorizeTokens($website);
        $tokens = $website->apiTokens()->with('creator:id,name')->latest('id')->get()->map(fn (ApiToken $token): array => [
            'id' => $token->id,
            'label' => $token->label,
            'hint' => $token->token_hint,
            'scopes' => $token->scopes,
            'rate_per_minute' => $token->rate_per_minute,
            'status' => $token->status(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'use_count' => $token->use_count,
            'created_at' => $token->created_at?->toIso8601String(),
            'created_by' => $token->creator?->name,
            'revoke_url' => route('sites.api-tokens.destroy', [$website, $token], false),
        ])->all();

        // The page may carry a freshly created secret: it must not be cached or kept readable in history.state.
        $created = $request->session()->get('api_token_created');
        if ($created !== null) {
            SecretPage::guard();
        }
        $response = Ui::render('sites.api-tokens', [
            'website' => $website,
            'apiTokens' => $tokens,
            'scopeOptions' => array_keys(ReportSections::SCOPES),
            'rateLimits' => $this->rates(),
            'apiBase' => url('/api/v1'),
            'created' => $created,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeTokens($website);
        $rates = $this->rates();
        $maxDays = config('perysee.api.max_expiry_days');
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ReportSections::SCOPES)), 'distinct'],
            'rate_per_minute' => ['nullable', 'integer', 'min:'.$rates['min'], 'max:'.$rates['max']],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', ...($maxDays ? ['before_or_equal:'.now($website->timezone)->addDays((int) $maxDays)->toDateString()] : [])],
        ]);
        $expires = isset($data['expires_on']) ? Carbon::parse($data['expires_on'], $website->timezone)->endOfDay()->utc() : null;
        [, $secret] = ApiToken::issue($website, $request->user(), $data['label'] ?? '', $data['scopes'], (int) ($data['rate_per_minute'] ?? $rates['default']), $expires);

        return redirect()->route('sites.api-tokens', $website)->with('api_token_created', ['token' => $secret]);
    }

    public function destroy(Website $website, string $token): RedirectResponse
    {
        $this->authorizeTokens($website);
        $model = $website->apiTokens()->findOrFail($token);
        $model->revoked_at ??= now();
        $model->save();

        return redirect()->route('sites.api-tokens', $website)->with('status', __('Token revoked.'));
    }
}
