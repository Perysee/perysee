<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the token itself is allowed to know: the site it covers and its own scopes. */
class SiteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $site = $request->attributes->get('api_site');
        $token = $request->attributes->get('api_token');

        return response()->json([
            'data' => [
                'site' => ['name' => $site->name, 'domain' => $site->domain, 'timezone' => $site->timezone],
                'token' => ['label' => $token->label, 'scopes' => $token->scopes, 'rate_per_minute' => $token->rate_per_minute, 'expires_at' => $token->expires_at?->toIso8601String()],
            ],
            'meta' => ['api_version' => 1],
        ]);
    }
}
