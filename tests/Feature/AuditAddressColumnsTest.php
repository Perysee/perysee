<?php

namespace Tests\Feature;

use App\Analytics\Audit\Checks\AddressStorageCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The audit says that no table holding visitor data has an address column (AddressStorageCheck). This reads the
 * real schema built by the migrations, so adding such a column fails here instead of making that sentence false.
 */
class AuditAddressColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS_COLUMN = '/(^|_)(ip|ips|ip_address|address|remote_addr|client_ip)(_|$)/';

    /** @return array<string, list<string>> */
    private function addressColumns(): array
    {
        $found = [];
        foreach (Schema::getTables() as $table) {
            $columns = array_values(array_filter(Schema::getColumnListing($table['name']), fn (string $column): bool => preg_match(self::ADDRESS_COLUMN, $column) === 1));
            if ($columns !== []) {
                $found[$table['name']] = $columns;
            }
        }

        return $found;
    }

    public function test_only_the_back_office_tables_have_an_address_column(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys($this->addressColumns()), AddressStorageCheck::BACK_OFFICE_TABLES)));
    }

    public function test_the_back_office_tables_named_by_the_audit_do_have_one(): void
    {
        $found = array_keys($this->addressColumns());
        sort($found);
        $expected = AddressStorageCheck::BACK_OFFICE_TABLES;
        sort($expected);

        $this->assertSame($expected, $found, 'The list in AddressStorageCheck must name exactly the tables that keep an address.');
    }
}
