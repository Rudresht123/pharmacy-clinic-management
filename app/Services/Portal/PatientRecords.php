<?php

namespace App\Services\Portal;

use App\Models\Tenant\Customer;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\Prescription;
use Illuminate\Database\Eloquent\Collection;

/**
 * The patient's own prescriptions and lab orders, read-only.
 *
 * Issued prescriptions and completed lab orders only: a draft prescription or
 * a pending order is the clinic's working copy, not yet something that
 * concerns the patient reading their own history.
 */
class PatientRecords
{
    /** Newest first, issued only. */
    public function recentPrescriptions(Customer $patient, int $limit = 3): Collection
    {
        return Prescription::query()
            ->where('customer_id', $patient->id)
            ->where('status', '<>', Prescription::DRAFT)
            ->with('doctor')
            ->orderByDesc('prescription_date')
            ->limit($limit)
            ->get();
    }

    /** Newest first, completed only -- a pending order has no result to show yet. */
    public function recentLabReports(Customer $patient, int $limit = 3): Collection
    {
        return LabOrder::query()
            ->where('customer_id', $patient->id)
            ->where('status', LabOrder::COMPLETED)
            ->with(['doctor', 'items'])
            ->orderByDesc('order_date')
            ->limit($limit)
            ->get();
    }
}
