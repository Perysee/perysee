<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/**
 * A static statement about the design, not a measurement: no table that holds visitor data has a column for an
 * address. AuditAddressColumnsTest checks it against the real schema, so adding such a column fails the build and
 * this text cannot go stale unnoticed. The back-office tables are named here because they do hold an address.
 */
final class AddressStorageCheck extends AbstractCheck
{
    /** Tables of the back office that have an address column: the access log and the device list keep it shortened. */
    public const BACK_OFFICE_TABLES = ['access_log', 'user_sessions', 'sessions'];

    public function id(): string
    {
        return 'measurement.address';
    }

    public function run(AuditContext $context): Finding
    {
        $databaseSessions = config('session.driver') === 'database';

        return $this->finding(
            Finding::OK,
            __('Visitor addresses'),
            __('no address column in the tables that hold visitor data'),
            __('The collector reads the address of a request to look up the country and to count requests (under a keyed hash of the address, in the cache), and stores the address nowhere. The access log and the device list of the back office keep the address of administrators and members shortened (/24 for IPv4, /48 for IPv6).')
                .($databaseSessions ? ' '.__('This instance stores back-office sessions in the database (the "sessions" table), which keeps the full address of signed-in users.') : ''),
            __('address truncated or not stored'),
        );
    }
}
