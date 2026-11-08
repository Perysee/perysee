<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;
use App\Updates\Version;

/**
 * The version of the Core against the newest release the last update check recorded. It reads that stored answer and
 * never calls GitHub: opening this page makes no request out of the server.
 */
final class VersionCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'instance.version';
    }

    public function run(AuditContext $context): Finding
    {
        $current = Version::normalize((string) config('perysee.version'));
        $title = __('Core version');
        $threshold = __('the newest release known at the last check');
        if (! config('perysee.update.check')) {
            return $this->finding(Finding::ATTENTION, $title, __('version :version, update checks are turned off', ['version' => $current]), __('Without update checks this instance does not learn about new releases, including security fixes. PERYSEE_UPDATE_CHECK turns them on.'), $threshold);
        }
        $check = $context->updateCheck();
        if ($check === null || ! is_string($check['checked_at'] ?? null)) {
            return $this->finding(Finding::ATTENTION, $title, __('version :version, no update check recorded yet', ['version' => $current]), __('The scheduler makes the check; use "Check for updates" on the Instance health page to make it now.'), $threshold, route('health', [], false));
        }
        $tag = $check['latest']['tag'] ?? null;
        $newer = is_string($tag) && Version::isNewer($tag, $current);
        $date = substr($check['checked_at'], 0, 10);

        return $this->finding(
            $newer ? Finding::ATTENTION : Finding::OK,
            $title,
            $newer
                ? __('version :version, :latest available (checked :date)', ['version' => $current, 'latest' => Version::normalize($tag), 'date' => $date])
                : __('version :version, no newer release known (checked :date)', ['version' => $current, 'date' => $date]),
            $newer
                ? __('A newer release was published. The Instance health page shows how to update.')
                : __('Based on the last update check, which this page does not repeat.').(is_string($check['error'] ?? null) ? ' '.__('The last check failed, so a newer release may exist.') : ''),
            $threshold,
            route('health', [], false),
        );
    }
}
