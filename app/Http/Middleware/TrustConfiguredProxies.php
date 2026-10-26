<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = array_filter(config('perysee.trusted_proxies', []), function (string $entry): bool {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false && ($bits === null || (ctype_digit($bits) && (int) $bits <= (str_contains($ip, ':') ? 128 : 32)));
        });
        $this->setTrustedProxyIpAddressesToSpecificIps($request, array_values($proxies));
    }
}
