<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillableService;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual invoice, as the counter drew it.
 *
 * Manual is the only creation path this endpoint carries — automatic
 * invoices are drawn inside `VisitWorkflow::completeConsultation` via
 * `BillingTriggerResolver`, and never come through here. That is what keeps
 * the schema honest: `trigger` on the row records which of those two acts
 * produced the row.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $locationId = $this->integer('location_id');

        return $user !== null
            && app(TenantBranchAccess::class)->canUse($user, $locationId ?: null);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $live = fn (string $model) => Rule::exists($model, 'id')->whereNull('deleted_at');
        $any = fn (string $model) => Rule::exists($model, 'id');

        return [
            'location_id' => ['required', 'integer', $any(Location::class)],

            // Somebody bought it — a registered patient, or a name at the counter.
            'customer_id' => ['nullable', 'integer', $live(Customer::class)],
            'walk_in_name' => ['nullable', 'string', 'max:120', 'required_without:customer_id'],
            'walk_in_phone' => ['nullable', 'string', 'max:20'],

            // Optional: an OPD visit this bill is attached to, if any.
            'appointment_id' => ['nullable', 'integer', $any(Appointment::class)],
            'consultation_id' => ['nullable', 'integer', $any(Consultation::class)],
            'doctor_id' => ['nullable', 'integer', $live(Doctor::class)],

            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.source_type' => ['required', 'string', Rule::in(InvoiceItem::SOURCES)],
            'items.*.source_id' => ['nullable', 'integer'],
            'items.*.billable_service_id' => ['nullable', 'integer', $any(BillableService::class)],
            'items.*.description' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'An invoice needs at least one line.',
            'walk_in_name.required_without' => 'Every invoice has to name a patient or a walk-in.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'location_id' => 'branch',
            'customer_id' => 'patient',
            'walk_in_name' => 'patient name',
            'items.*.description' => 'line description',
        ];
    }
}
