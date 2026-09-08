<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateCustomerRequest;
use App\Http\Resources\Tenant\CustomerResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\User;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Fields\FieldSchema;
use App\Services\Permissions\Permission;
use App\Support\Fields\CustomerFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The people an organization serves.
 *
 * Open to any signed-in tenant user, unlike Locations and People: serving a
 * customer at the counter means being able to create one. Deleting is the
 * exception — that is an owner decision, and the route says so.
 */
class CustomerController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly Permission $permission,
    ) {}

    /**
     * The branch to record a customer against.
     *
     * Resolved from memberships through Permission rather than read off
     * `users.location_id`, so somebody who works at two branches is stamped
     * with the one they are actually working in — and the owner and head
     * office, who work across the network, still get null and choose.
     */
    private function callersBranch(): ?int
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $this->permission->branchFor($user) : null;
    }

    /** The field definitions the form and table render from. */
    public function fields(FieldSchema $schema): JsonResponse
    {
        $fields = $schema->for(EntityFieldSetting::ENTITY_CUSTOMER, CustomerFields::all());

        /*
         * Somebody who works at a branch never picks one: their own is
         * recorded for them, so the field comes off their form. The owner,
         * who has no branch, still chooses.
         */
        if ($this->callersBranch() !== null) {
            $fields = array_map(
                fn (array $field) => $field['key'] === 'registered_location_id'
                    ? [...$field, 'show_in_form' => false]
                    : $field,
                $fields
            );
        }

        return $this->ok(array_values($fields));
    }

    /**
     * The counts the list screen leads with.
     *
     * Separate from index() because that answers one page at a time, and a
     * total across every page is a different question.
     */
    public function stats(): JsonResponse
    {
        return $this->ok($this->customers->stats());
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->customers->listing(
            activeOnly: $request->boolean('active_only'),
            status: $request->string('status')->toString() ?: null,
            registeredLocationId: $request->filled('registered_location_id')
                ? (int) $request->input('registered_location_id')
                : null,
            // Named rather than positional: the filter card grows, and a
            // fifth boolean argument here would be unreadable.
            filters: $request->only(['gender', 'age_band', 'city', 'joined_within']),
        );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                // Phone first in spirit: the counter searches by number.
                searchable: ['code', 'name', 'phone', 'email', 'city'],
                sortable: ['name', 'phone', 'city', 'is_active', 'created_at'],
                defaultSort: 'created_at',
            ),
            CustomerResource::class,
        );
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->ok(CustomerResource::make($customer));
    }

    /**
     * Everything that has happened to this patient, and what it adds up to.
     *
     * Appointments and what was written up at each, together — they are read
     * as one thing ("what happened on the 12th"), and separating them would
     * mean a screen joining two lists by date to put them back.
     *
     * The summary beside them is derived from the same rows rather than stored:
     * a "total visits" column would be a number that drifts the first time an
     * appointment is cancelled.
     */
    public function visits(Customer $customer): JsonResponse
    {
        $visits = Appointment::on('organization')
            ->with(['doctor', 'location', 'consultation'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->limit(100)
            ->get();

        return $this->ok([
            'summary' => $this->summarise($customer, $visits),

            'visits' => $visits->map(fn (Appointment $visit) => [
                'id' => $visit->id,
                'date' => $visit->appointment_date?->toDateString(),
                'status' => $visit->status,
                'type' => $visit->type,
                'slot_at' => $visit->slot_at ? substr((string) $visit->slot_at, 0, 5) : null,
                'token_no' => $visit->token_no,
                'doctor_name' => $visit->doctor?->name,
                'location_name' => $visit->location?->name,

                // Null when nobody wrote the visit up — a fact about the visit,
                // not a gap to paper over.
                'consultation' => $visit->consultation ? [
                    'chief_complaint' => $visit->consultation->chief_complaint,
                    'diagnoses' => $visit->consultation->diagnoses ?? [],
                    'advice' => $visit->consultation->advice,
                    'notes' => $visit->consultation->notes,
                    'follow_up_days' => $visit->consultation->follow_up_days,
                    'vitals' => $visit->consultation->vitals ?? [],
                    'prescription' => $visit->consultation->prescription ?? [],
                    'investigations' => $visit->consultation->investigations ?? [],
                ] : null,
            ])->all(),
        ]);
    }

    /**
     * What the visits add up to.
     *
     * @param  \Illuminate\Support\Collection<int, Appointment>  $visits
     * @return array<string, mixed>
     */
    private function summarise(Customer $customer, $visits): array
    {
        $seen = $visits->where('status', Appointment::STATUS_COMPLETED);

        $last = $seen->first();

        // The next one still to come, by date then time.
        $next = $visits
            ->where('status', Appointment::STATUS_BOOKED)
            ->filter(fn (Appointment $visit) => $visit->appointment_date?->isFuture()
                || $visit->appointment_date?->isToday())
            ->sortBy([
                fn (Appointment $a, Appointment $b) => $a->appointment_date <=> $b->appointment_date,
                fn (Appointment $a, Appointment $b) => $a->slot_at <=> $b->slot_at,
            ])
            ->first();

        /*
         * The most recent visit that actually recorded each thing.
         *
         * Not the latest visit's values: a doctor who took no vitals on
         * Tuesday has not cancelled Monday's, and showing blanks because the
         * newest row happens to be empty would lose the only reading there is.
         */
        $withVitals = $seen->first(fn (Appointment $visit) => ! empty($visit->consultation?->vitals));
        $withDrugs = $seen->first(fn (Appointment $visit) => ! empty($visit->consultation?->prescription));

        return [
            'total_visits' => $visits->count(),
            'seen_count' => $seen->count(),
            'member_since' => $customer->created_at?->toDateString(),

            'last_visit' => $last ? [
                'on' => $last->appointment_date?->toDateString(),
                'doctor_name' => $last->doctor?->name,
            ] : null,

            'next_visit' => $next ? [
                'on' => $next->appointment_date?->toDateString(),
                'at' => $next->slot_at ? substr((string) $next->slot_at, 0, 5) : null,
                'doctor_name' => $next->doctor?->name,
            ] : null,

            'vitals' => $withVitals ? [
                'on' => $withVitals->appointment_date?->toDateString(),
                'values' => $withVitals->consultation->vitals,
            ] : null,

            'medications' => $withDrugs ? [
                'on' => $withDrugs->appointment_date?->toDateString(),
                'lines' => $withDrugs->consultation->prescription,
            ] : null,

            /*
             * What this patient has been diagnosed with, most recent first.
             *
             * Called "recorded diagnoses" rather than "chronic conditions":
             * nothing marks a diagnosis as chronic, and a screen that promoted
             * a one-off chest infection to a standing condition would be
             * inventing a clinical fact.
             */
            'diagnoses' => $seen
                ->flatMap(fn (Appointment $visit) => $visit->consultation?->diagnoses ?? [])
                ->unique()
                ->take(8)
                ->values()
                ->all(),
        ];
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();

        /*
         * Enforced here rather than merely hidden on the form. Taking the
         * branch off the screen is a UI convenience; overriding whatever was
         * submitted is what actually makes "you cannot record the wrong
         * branch" true, including for anything talking to the API directly.
         */
        if (($branch = $this->callersBranch()) !== null) {
            $data['registered_location_id'] = $branch;
        }

        $customer = $this->customers->create($data);

        return $this->created(CustomerResource::make($customer), 'Customer added successfully.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $updated = $this->customers->update($customer, $request->validated());

        return $this->ok(CustomerResource::make($updated), 'Customer updated successfully.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        /*
         * Soft delete: purchases, prescriptions and ledger entries will all
         * point at this row, and a hard delete would orphan the history the
         * record exists to hold together.
         */
        $this->customers->delete($customer);

        return $this->noContent('Customer removed successfully.');
    }
}
