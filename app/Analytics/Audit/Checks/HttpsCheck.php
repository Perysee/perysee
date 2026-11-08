<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** Whether the configured address of the instance (APP_URL) and the request that opened this page use HTTPS. */
final class HttpsCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'instance.https';
    }

    public function run(AuditContext $context): Finding
    {
        $configured = str_starts_with(strtolower((string) config('app.url')), 'https://');
        $secure = $context->request()->isSecure();
        $status = match (true) {
            $configured && $secure => Finding::OK,
            ! $configured && ! $secure && app()->environment('production') => Finding::PROBLEM,
            default => Finding::ATTENTION,
        };

        return $this->finding(
            $status,
            __('HTTPS'),
            __('APP_URL :configured, this request :request', ['configured' => $configured ? __('uses https') : __('does not use https'), 'request' => $secure ? __('is secure') : __('is not secure')]),
            match (true) {
                $configured && $secure => __('The address of the instance and this connection both use HTTPS.'),
                $configured => __('APP_URL uses HTTPS but this request arrived without it. Behind a proxy, check the trusted proxies setting (PERYSEE_TRUSTED_PROXIES).'),
                $secure => __('This connection is secure but APP_URL does not use HTTPS, so the links and the tracking snippet the instance builds point to an unsecured address.'),
                default => __('Neither APP_URL nor this connection uses HTTPS: passwords and sessions travel unencrypted.'),
            },
            __('APP_URL and the request both on https'),
        );
    }
}
