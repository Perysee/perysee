<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;
use App\Updates\Backup;
use Illuminate\Support\Carbon;

/**
 * The newest backup the updater made, read from the `backup.json` it writes in the backup folder. Perysee cannot see
 * a backup made by the host or by hand, so "none found" is a reason to look, not a statement that none exists.
 */
final class BackupCheck extends AbstractCheck
{
    /** A backup older than this many days is reported. */
    public const MAX_AGE_DAYS = 30;

    public function id(): string
    {
        return 'instance.backup';
    }

    public function run(AuditContext $context): Finding
    {
        $at = $this->latest();
        $age = $at === null ? null : (int) $at->diffInDays(now('UTC'), true);
        $recent = $age !== null && $age <= self::MAX_AGE_DAYS;

        return $this->finding(
            $recent ? Finding::OK : Finding::ATTENTION,
            __('Backup'),
            $at === null
                ? __('no backup made by the updater was found')
                : __('the latest backup made by the updater is from :date (:days days ago)', ['date' => $at->toDateString(), 'days' => $age]),
            __('The updater saves the database and the private files before each update. A backup made by your host or by hand is not visible to Perysee, so this says nothing about those.'),
            __('a backup made in the last :days days', ['days' => self::MAX_AGE_DAYS]),
        );
    }

    private function latest(): ?Carbon
    {
        foreach (array_reverse(app(Backup::class)->all()) as $folder) {
            $record = is_file($folder.'/backup.json') ? json_decode((string) file_get_contents($folder.'/backup.json'), true) : null;
            if (is_array($record) && is_string($record['created_at'] ?? null)) {
                return Carbon::parse($record['created_at'], 'UTC');
            }
        }

        return null;
    }
}
