<?php

namespace App\Analytics\Ingestion;

class Device
{
    public function classify(string $agent, ?string $hint = null): string
    {
        $agent = strtolower($agent);
        if ($agent === '' || preg_match('/bot|crawler|spider|curl|wget/', $agent)) {
            return 'unknown';
        }
        if (preg_match('/ipad|tablet|android(?!.*mobile)/', $agent) || ($hint === 'tablet' && str_contains($agent, 'macintosh'))) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android/', $agent)) {
            return 'mobile';
        }

        return preg_match('/windows nt|macintosh|x11|cros/', $agent) ? 'desktop' : 'unknown';
    }

    /** Only coarse allowlisted labels survive the request; the raw header is never stored. */
    public function details(string $agent, ?string $hint = null): array
    {
        $agent = strtolower(substr($agent, 0, 4096));
        $result = ['device_type' => $this->classify($agent, $hint), 'device_brand' => 'unknown', 'browser_name' => 'unknown'];
        if ($agent === '' || preg_match('/bot|crawler|spider|curl|wget/', $agent)) {
            return $result;
        }
        foreach ([
            'samsung' => '/\bsamsungbrowser\//', 'edge' => '/\b(?:edg|edga|edgios|edge)\//',
            'opera' => '/\b(?:opr|opera|opios)\//', 'firefox' => '/\b(?:firefox|fxios)\//',
            'chromium' => '/\bchromium\//', 'chrome' => '/\b(?:chrome|crios)\//',
            'safari' => '/\bversion\/.*\bsafari\//', 'ie' => '/\b(?:msie |trident\/)/',
        ] as $browser => $pattern) {
            if (preg_match($pattern, $agent)) {
                $result['browser_name'] = $browser;
                break;
            }
        }
        foreach ([
            'apple' => '/\b(?:iphone|ipad|ipod|macintosh)\b/',
            'samsung' => '/\b(?:samsung|sm-[a-z0-9]+|gt-[a-z0-9]+)\b/',
            'google' => '/\bpixel(?: [a-z0-9]+)?\b/',
            'xiaomi' => '/\b(?:xiaomi|redmi|poco)\b/',
            'huawei' => '/\bhuawei\b/', 'honor' => '/\bhonor\b/',
            'motorola' => '/\b(?:motorola|moto [a-z0-9]+)\b/',
            'lenovo' => '/\blenovo\b/', 'asus' => '/\basus\b/',
        ] as $brand => $pattern) {
            if (preg_match($pattern, $agent)) {
                $result['device_brand'] = $brand;
                break;
            }
        }

        return $result;
    }
}
