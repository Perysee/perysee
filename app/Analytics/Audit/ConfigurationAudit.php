<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

use App\Analytics\Audit\Checks\AddressStorageCheck;
use App\Analytics\Audit\Checks\AdministratorCountCheck;
use App\Analytics\Audit\Checks\AllowedDomainsCheck;
use App\Analytics\Audit\Checks\BackupCheck;
use App\Analytics\Audit\Checks\ContinuityBreaksCheck;
use App\Analytics\Audit\Checks\CookieCheck;
use App\Analytics\Audit\Checks\HttpsCheck;
use App\Analytics\Audit\Checks\MeasurementModeCheck;
use App\Analytics\Audit\Checks\RawRetentionCheck;
use App\Analytics\Audit\Checks\SchedulerCheck;
use App\Analytics\Audit\Checks\StatsRetentionCheck;
use App\Analytics\Audit\Checks\StorageBudgetCheck;
use App\Analytics\Audit\Checks\TwoFactorCheck;
use App\Analytics\Audit\Checks\VersionCheck;

/**
 * Runs the checks in order and collects their findings (ADR 009). It reads and writes nothing itself. A check that
 * throws becomes an "unavailable" finding, so that one unreadable source never costs the administrator the page.
 */
final class ConfigurationAudit
{
    /** @param  list<Check>|null  $checks  Replaces the default registry (tests, and later the modules). */
    public function __construct(private readonly ?array $checks = null) {}

    /** The checks of the registry, in the order of the page. @return list<Check> */
    public function checks(AuditProfile $profile): array
    {
        return $this->checks ?? [
            new RawRetentionCheck, new StatsRetentionCheck,
            new MeasurementModeCheck, new CookieCheck, new AddressStorageCheck,
            new AllowedDomainsCheck,
            new HttpsCheck, new SchedulerCheck, new BackupCheck, new VersionCheck, new StorageBudgetCheck,
            new TwoFactorCheck, new AdministratorCountCheck,
            new ContinuityBreaksCheck,
        ];
    }

    /** @return list<Finding> */
    public function run(AuditContext $context): array
    {
        $findings = [];
        foreach ($this->checks($context->profile) as $check) {
            try {
                $findings[] = $check->run($context);
            } catch (\Throwable $failure) {
                // The cause goes to the log; the page only says that the check could not run.
                report($failure);
                $findings[] = Finding::unavailable($check->id());
            }
        }

        return $findings;
    }
}
