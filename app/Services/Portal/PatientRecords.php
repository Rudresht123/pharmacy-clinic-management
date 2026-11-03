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
    /** What PortalPrescriptionResource needs to describe one in full. */
    public const PRESCRIPTION_RELATIONS = ['doctor', 'location', 'items', 'consultation'];

    /** What PortalLabReportResource needs to describe one in full. */
    public const LAB_RELATIONS = ['doctor', 'location', 'items'];

    /** All of one person's, newest first. A draft is still the doctor's own working copy. */
    public function prescriptionsOf(Customer $patient): Collection
    {
        return Prescription::query()
            ->where('customer_id', $patient->id)
            ->where('status', '<>', Prescription::DRAFT)
            ->with(self::PRESCRIPTION_RELATIONS)
            ->latest('prescription_date')
            ->get();
    }

    /** All of one person's orders, newest first, whatever has become of them. */
    public function labReportsOf(Customer $patient): Collection
    {
        return LabOrder::query()
            ->where('customer_id', $patient->id)
            ->with(self::LAB_RELATIONS)
            ->latest('order_date')
            ->get();
    }

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
