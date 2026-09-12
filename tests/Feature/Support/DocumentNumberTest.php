<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * hms_document_number(): the database hands out document numbers.
 *
 * Called directly here because no table uses it as a default yet; from
 * Phase 1 on, the tables do, and the application never calls it itself.
 */
class DocumentNumberTest extends TenantTestCase
{
    use RefreshDatabase;

    private function next(string $prefix, string $sequence): string
    {
        return (string) DB::connection('organization')->scalar(
            'SELECT hms_document_number(?, ?::regclass)',
            [$prefix, $sequence],
        );
    }

    public function test_numbers_are_consecutive_and_zero_padded(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            $this->assertSame('RX-00001', $this->next('RX', 'prescription_number_seq'));
            $this->assertSame('RX-00002', $this->next('RX', 'prescription_number_seq'));
            $this->assertSame('RX-00003', $this->next('RX', 'prescription_number_seq'));
        });
    }

    /** Each document type counts on its own. */
    public function test_every_document_type_has_its_own_sequence(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            $this->next('RX', 'prescription_number_seq');
            $this->next('RX', 'prescription_number_seq');

            $this->assertSame('DS-00001', $this->next('DS', 'dispensing_number_seq'));
            $this->assertSame('GRN-00001', $this->next('GRN', 'stock_inward_number_seq'));
            $this->assertSame('ADJ-00001', $this->next('ADJ', 'stock_adjustment_number_seq'));
            $this->assertSame('TRF-00001', $this->next('TRF', 'stock_transfer_number_seq'));
            $this->assertSame('RX-00003', $this->next('RX', 'prescription_number_seq'));
        });
    }

    /** Past five digits the number grows; it never wraps or truncates. */
    public function test_the_number_widens_after_99999(): void
    {
        $organization = $this->provisionOrganization();

        $this->onTenant($organization, function () {
            DB::connection('organization')->statement("SELECT setval('prescription_number_seq', 99999, false)");

            $this->assertSame('RX-99999', $this->next('RX', 'prescription_number_seq'));
            $this->assertSame('RX-100000', $this->next('RX', 'prescription_number_seq'));
        });
    }

    /** Each organization has its own register, in its own database. */
    public function test_organizations_do_not_share_a_sequence(): void
    {
        $first = $this->provisionOrganization('A');
        $second = $this->provisionOrganization('B');

        $this->onTenant($first, fn () => $this->next('RX', 'prescription_number_seq'));
        $this->onTenant($first, fn () => $this->next('RX', 'prescription_number_seq'));

        $this->assertSame(
            'RX-00001',
            $this->onTenant($second, fn () => $this->next('RX', 'prescription_number_seq')),
        );
    }
}
