<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Documents\DocumentCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One patient's records, gathered from every module, as one person may see them.
 *
 * Nothing here is stored and nothing is new: appointments, prescriptions, lab
 * orders, pharmacy bills, invoices and documents are each read from their own
 * module's table through its own model. What this adds is the one place that
 * asks, for every section, the SAME two questions that module's own list asks:
 *
 *   may this person read it at all  — the module running where they work and
 *                                     the capability held there, through
 *                                     Permission, exactly as the route
 *                                     middleware does;
 *   which of it                     — the branches they may act at, through
 *                                     TenantBranchAccess. The owner sees every
 *                                     branch; staff the branches they work at;
 *                                     head office, which works at none, sees
 *                                     no branch's records.
 *
 * The patient themselves is the organization's, not a branch's — one row per
 * person in the tenant database, shared by every branch. That is how the same
 * person's visits at two branches link up: by `customer_id`, the identity the
 * modules already record. Records never cross organizations, because each
 * organization's are in its own database.
 *
 * A section the caller may not read comes back as null, never as zero: "you
 * cannot see this" and "there is nothing" are different answers, and a screen
 * printing 0 for the first would be telling somebody a patient has no
 * prescriptions when it only means they may not look.
 */
class PatientRecord
{
    /**
     * The statuses of a visit the patient never came to.
     *
     * Everything else — checked in, with the doctor, waiting on the pharmacy,
     * closed — means they arrived, which is what "a visit" is on this screen.
     */
    public const NOT_ARRIVED = [
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CANCELLED,
        Appointment::STATUS_NO_SHOW,
    ];

    /** How many events the overview's Recent Activity card shows. */
    public const RECENT = 6;

    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Who may see what
    |--------------------------------------------------------------------------
    */

    /**
     * Which sections this person may open.
     *
     * Visits are behind `customers.view` plus the OPD module — the same rule
     * as the patient file's own visits endpoint: being able to see a patient
     * at all is what makes their visits readable. Every other section asks
     * for its module's own read capability, which `allows()` checks against
     * the module too.
     *
     * @return array{appointments: bool, visits: bool, prescriptions: bool, laboratory: bool, pharmacy: bool, billing: bool, documents: bool}
     */
    public function sections(Organization $organization, User $user): array
    {
        $can = fn (string $capability) => $this->permission->allows($organization, $user, $capability);

        $visits = $can('customers.view')
            && $this->permission->hasModule($organization, 'appointments', $this->permission->branchFor($user));

        return [
            'appointments' => $visits,
            'visits' => $visits,
            'prescriptions' => $can('prescriptions.view'),
            'laboratory' => $can('laboratory.view'),
            'pharmacy' => $can('pharmacy.view'),
            'billing' => $can('billing.view'),
            'documents' => $can('documents.view'),
        ];
    }

    /** Whether clinical documents are readable too — asked per record by the documents module. */
    public function readsClinicalDocuments(Organization $organization, User $user): bool
    {
        return $this->permission->allows($organization, $user, 'documents.view_clinical');
    }

    /**
     * The branches this person may see, or null for all of them.
     *
     * @return list<int>|null
     */
    public function allowed(User $user): ?array
    {
        return $this->branches->allowed($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Each module's records for one patient, at the branches allowed
    |--------------------------------------------------------------------------
    */

    public function appointments(Customer $customer, ?array $allowed): Builder
    {
        return $this->atBranches(Appointment::query()->where('customer_id', $customer->getKey()), $allowed);
    }

    /** The visits they actually came to. */
    public function visits(Customer $customer, ?array $allowed): Builder
    {
        return $this->appointments($customer, $allowed)->whereNotIn('status', self::NOT_ARRIVED);
    }

    public function prescriptions(Customer $customer, ?array $allowed): Builder
    {
        return $this->atBranches(Prescription::query()->where('customer_id', $customer->getKey()), $allowed);
    }

    public function labOrders(Customer $customer, ?array $allowed): Builder
    {
        return $this->atBranches(LabOrder::query()->where('customer_id', $customer->getKey()), $allowed);
    }

    public function pharmacySales(Customer $customer, ?array $allowed): Builder
    {
        return $this->atBranches(PharmacySale::query()->where('customer_id', $customer->getKey()), $allowed);
    }

    public function invoices(Customer $customer, ?array $allowed): Builder
    {
        return $this->atBranches(Invoice::query()->where('customer_id', $customer->getKey()), $allowed);
    }

    /**
     * The patient's folder.
     *
     * Not narrowed by branch — the documents module keeps one folder per
     * patient for the whole organization — but narrowed by sensitivity, with
     * the module's own scope, so a count never includes a scan this person
     * would be refused.
     */
    public function documents(Customer $customer, bool $clinical): Builder
    {
        return PatientDocument::query()
            ->where('customer_id', $customer->getKey())
            ->readableBy($clinical);
    }

    /**
     * The date somebody last came in, as a sub-select for the patient list.
     *
     * Null when the caller cannot read visits, rather than a date drawn from
     * branches they cannot see.
     */
    public function lastVisitSubquery(?array $allowed): Builder
    {
        return $this->atBranches(Appointment::query(), $allowed)
            ->select('appointment_date')
            ->whereColumn('appointments.customer_id', 'customers.id')
            ->whereNotIn('status', self::NOT_ARRIVED)
            ->orderByDesc('appointment_date')
            ->limit(1);
    }

    /*
    |--------------------------------------------------------------------------
    | The figures the list screen leads with
    |--------------------------------------------------------------------------
    */

    /**
     * Four cards, each only where its source is readable.
     *
     * The patient register is the organization's, like the list beside it, so
     * the two patient cards count the whole book — the same total the list
     * prints. The two visit cards are narrowed to the caller's branches and
     * need the OPD's own read capability, as the dashboard's do.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(Organization $organization, User $user): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();

        $total = Customer::query()->count();
        $before = Customer::query()->where('created_at', '<', $monthStart)->count();
        $thisMonth = Customer::query()->where('created_at', '>=', $monthStart)->count();
        $lastMonth = Customer::query()
            ->whereBetween('created_at', [$lastMonthStart, $monthStart->copy()->subSecond()])
            ->count();

        $cards = [
            [
                'key' => 'total',
                'label' => 'Total Patients',
                'value' => $total,
                // How much the register grew this month.
                'change' => $this->change($total, $before),
                'hint' => 'from last month',
            ],
            [
                'key' => 'new',
                'label' => 'New Patients',
                'value' => $thisMonth,
                'change' => $this->change($thisMonth, $lastMonth),
                'hint' => 'this month',
            ],
        ];

        $opd = $this->permission->hasModule($organization, 'appointments', $this->permission->branchFor($user))
            && $this->permission->allows($organization, $user, 'appointments.view');

        if ($opd) {
            $allowed = $this->allowed($user);
            $today = $now->copy()->startOfDay();

            $booked = fn (Carbon $from, Carbon $to) => $this->atBranches(Appointment::query(), $allowed)
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->count();

            $arrived = fn (Carbon $from, Carbon $to) => $this->atBranches(Appointment::query(), $allowed)
                ->whereNotIn('status', self::NOT_ARRIVED)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->count();

            $upcoming = $this->atBranches(Appointment::query(), $allowed)
                ->where('status', Appointment::STATUS_BOOKED)
                ->whereBetween('appointment_date', [$today->toDateString(), $today->copy()->addDays(6)->toDateString()])
                ->count();

            $recent = $arrived($today->copy()->subDays(29), $today);

            $cards[] = [
                'key' => 'upcoming',
                'label' => 'Upcoming Appointments',
                'value' => $upcoming,
                // Against the week just gone: was the book this full then?
                'change' => $this->change($upcoming, $booked($today->copy()->subDays(7), $today->copy()->subDay())),
                'hint' => 'in next 7 days',
            ];

            $cards[] = [
                'key' => 'recent',
                'label' => 'Recent Visits',
                'value' => $recent,
                'change' => $this->change($recent, $arrived($today->copy()->subDays(59), $today->copy()->subDays(30))),
                'hint' => 'last 30 days',
            ];
        }

        return $cards;
    }

    /**
     * The branches this person may filter by — the network for the owner,
     * their own for everybody else.
     *
     * @return list<array{id: int, name: string}>
     */
    public function branchOptions(User $user): array
    {
        $allowed = $this->allowed($user);

        return $this->atBranches(Location::query(), $allowed, 'id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | One patient, summarised
    |--------------------------------------------------------------------------
    */

    /**
     * What the profile leads with: a count on every tab, the money, the visit
     * before and the visit after, and the branches this person's records sit at.
     *
     * @return array<string, mixed>
     */
    public function summary(Organization $organization, User $user, Customer $customer): array
    {
        $open = $this->sections($organization, $user);
        $allowed = $this->allowed($user);
        $clinical = $this->readsClinicalDocuments($organization, $user);

        $counts = [
            'appointments' => $open['appointments'] ? $this->appointments($customer, $allowed)->count() : null,
            'visits' => $open['visits'] ? $this->visits($customer, $allowed)->count() : null,
            'prescriptions' => $open['prescriptions'] ? $this->prescriptions($customer, $allowed)->count() : null,
            'laboratory' => $open['laboratory'] ? $this->labOrders($customer, $allowed)->count() : null,
            'pharmacy' => $open['pharmacy'] ? $this->pharmacySales($customer, $allowed)->count() : null,
            'billing' => $open['billing'] ? $this->invoices($customer, $allowed)->count() : null,
            'documents' => $open['documents'] ? $this->documents($customer, $clinical)->count() : null,
        ];

        return [
            'sections' => $counts,
            'billing' => $open['billing'] ? $this->money($customer, $allowed) : null,
            // Each bill's own amountDue(), so a bill settled through an
            // invoice is never counted as owed at the counter as well.
            'pharmacy_due' => $open['pharmacy']
                ? round($this->pharmacySales($customer, $allowed)
                    ->completed()
                    ->get()
                    ->sum(fn (PharmacySale $sale) => $sale->amountDue()), 2)
                : null,
            'last_visit' => $open['visits'] ? $this->lastVisit($customer, $allowed) : null,
            'next_visit' => $open['appointments'] ? $this->nextVisit($customer, $allowed) : null,
            'branches' => $this->linkedBranches($customer, $open, $allowed),
            'activity' => $this->timeline($organization, $user, $customer, self::RECENT),
        ];
    }

    /**
     * Billed, paid and owed, across the bills this person may see.
     *
     * A cancelled bill was never owed, so it is left out of every figure
     * rather than counted and then subtracted. What is owed is each bill's
     * own outstanding(), the same arithmetic the invoice screen prints.
     *
     * @return array{billed: float, paid: float, outstanding: float}
     */
    private function money(Customer $customer, ?array $allowed): array
    {
        $live = $this->invoices($customer, $allowed)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->get(['id', 'status', 'payment_status', 'total_amount', 'paid_amount', 'refunded_amount']);

        $owing = $live->filter(fn (Invoice $invoice) => in_array($invoice->status, Invoice::OWES_MONEY, true)
            && $invoice->payment_status !== Invoice::PAID);

        return [
            'billed' => round((float) $live->sum('total_amount'), 2),
            'paid' => round((float) $live->sum('paid_amount') - (float) $live->sum('refunded_amount'), 2),
            'outstanding' => round($owing->sum(fn (Invoice $invoice) => $invoice->outstanding()), 2),
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastVisit(Customer $customer, ?array $allowed): ?array
    {
        $visit = $this->visits($customer, $allowed)
            ->with(['doctor', 'location'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->first();

        return $visit ? [
            'on' => $visit->appointment_date?->toDateString(),
            'doctor_name' => $visit->doctor?->name,
            'location_name' => $visit->location?->name,
        ] : null;
    }

    /** The next booking still to come, by date then time. */
    private function nextVisit(Customer $customer, ?array $allowed): ?array
    {
        $visit = $this->appointments($customer, $allowed)
            ->with(['doctor', 'location'])
            ->where('status', Appointment::STATUS_BOOKED)
            ->whereDate('appointment_date', '>=', Carbon::today())
            ->orderBy('appointment_date')
            ->orderBy('slot_at')
            ->first();

        return $visit ? [
            'on' => $visit->appointment_date?->toDateString(),
            'at' => $visit->slot_at ? substr((string) $visit->slot_at, 0, 5) : null,
            'doctor_name' => $visit->doctor?->name,
            'location_name' => $visit->location?->name,
        ] : null;
    }

    /**
     * Every branch this patient has a record at, among the ones this person
     * may see — how one person's history across the network shows up as one.
     *
     * Where they registered is included even with nothing else there yet:
     * that is where they first walked in.
     *
     * @param  array<string, bool>  $open
     * @return list<array{id: int, name: string}>
     */
    private function linkedBranches(Customer $customer, array $open, ?array $allowed): array
    {
        $ids = collect();

        if ($customer->registered_location_id !== null
            && ($allowed === null || in_array((int) $customer->registered_location_id, $allowed, true))) {
            $ids->push((int) $customer->registered_location_id);
        }

        $sources = [
            'appointments' => fn () => $this->appointments($customer, $allowed),
            'prescriptions' => fn () => $this->prescriptions($customer, $allowed),
            'laboratory' => fn () => $this->labOrders($customer, $allowed),
            'pharmacy' => fn () => $this->pharmacySales($customer, $allowed),
            'billing' => fn () => $this->invoices($customer, $allowed),
        ];

        foreach ($sources as $section => $query) {
            if ($open[$section]) {
                $ids = $ids->merge($query()->distinct()->pluck('location_id')->map(fn ($id) => (int) $id));
            }
        }

        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Location::query()
            ->whereIn('id', $ids->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | The timeline
    |--------------------------------------------------------------------------
    */

    /**
     * Everything that happened to this patient, newest first, across modules.
     *
     * Each module contributes its own newest `$limit` rows — never more can
     * survive the merge — and the merged list is cut to `$limit`. Each event
     * names the tab that holds it in full, so a click goes to the record
     * rather than to a copy of it.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(Organization $organization, User $user, Customer $customer, int $limit = 50): array
    {
        $open = $this->sections($organization, $user);
        $allowed = $this->allowed($user);

        $events = collect();

        if ($open['appointments']) {
            $events = $events->merge($this->appointmentEvents($customer, $allowed, $limit));
        }

        if ($open['prescriptions']) {
            $events = $events->merge($this->prescriptionEvents($customer, $allowed, $limit));
        }

        if ($open['laboratory']) {
            $events = $events->merge($this->labEvents($customer, $allowed, $limit));
        }

        if ($open['pharmacy']) {
            $events = $events->merge($this->pharmacyEvents($customer, $allowed, $limit));
        }

        if ($open['billing']) {
            $events = $events->merge($this->invoiceEvents($customer, $allowed, $limit));
        }

        if ($open['documents']) {
            $events = $events->merge(
                $this->documentEvents($customer, $this->readsClinicalDocuments($organization, $user), $limit)
            );
        }

        return $events
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * A visit they came to reads as one, with what the doctor wrote; one they
     * did not — cancelled, missed, or booked for today — reads as the booking.
     *
     * Bookings for a later day are left out: this is what has happened, and
     * what is still to come is the profile's "next visit" and the
     * Appointments tab.
     */
    private function appointmentEvents(Customer $customer, ?array $allowed, int $limit): Collection
    {
        return $this->appointments($customer, $allowed)
            ->whereDate('appointment_date', '<=', Carbon::today())
            ->with(['doctor', 'location', 'consultation'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->limit($limit)
            ->get()
            ->map(function (Appointment $visit) {
                $came = ! in_array($visit->status, self::NOT_ARRIVED, true);
                $slot = $this->slotTime($visit);

                $consultation = $visit->consultation;
                $diagnoses = $consultation?->diagnoses ?? [];

                return $this->event(
                    key: "appointment-{$visit->id}",
                    kind: $came ? 'visit' : 'appointment',
                    section: $came ? 'visits' : 'appointments',
                    title: $came ? 'OPD Visit' : 'Appointment',
                    at: $came ? ($visit->started_at ?? $slot ?? $visit->checked_in_at) : $slot,
                    date: $visit->appointment_date,
                    status: $visit->status,
                    doctor: $visit->doctor?->name,
                    location: $visit->location?->name,
                    detail: $came
                        ? ($consultation?->chief_complaint ?: (implode(', ', $diagnoses) ?: null))
                        : ($visit->mode === Appointment::MODE_VIDEO ? 'Video consultation' : null),
                );
            });
    }

    private function prescriptionEvents(Customer $customer, ?array $allowed, int $limit): Collection
    {
        return $this->prescriptions($customer, $allowed)
            ->with(['doctor', 'location'])
            ->withCount('items')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Prescription $prescription) => $this->event(
                key: "prescription-{$prescription->id}",
                kind: 'prescription',
                section: 'prescriptions',
                title: 'Prescription',
                at: $prescription->issued_at ?? $prescription->created_at,
                date: $prescription->prescription_date,
                status: $prescription->status,
                doctor: $prescription->doctor?->name,
                location: $prescription->location?->name,
                detail: $prescription->prescription_number,
                count: (int) $prescription->items_count,
            ));
    }

    private function labEvents(Customer $customer, ?array $allowed, int $limit): Collection
    {
        return $this->labOrders($customer, $allowed)
            ->with(['doctor', 'location', 'items'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (LabOrder $order) => $this->event(
                key: "lab-{$order->id}",
                kind: 'lab',
                section: 'laboratory',
                title: $order->status === LabOrder::COMPLETED ? 'Lab Report' : 'Lab Order',
                at: $order->completed_at ?? $order->created_at,
                date: $order->order_date,
                status: $order->status,
                doctor: $order->doctor?->name,
                location: $order->location?->name,
                detail: $order->testSummary() ?: $order->order_number,
                count: $order->items->count(),
            ));
    }

    private function pharmacyEvents(Customer $customer, ?array $allowed, int $limit): Collection
    {
        return $this->pharmacySales($customer, $allowed)
            ->with(['store.location'])
            ->withCount('items')
            ->orderByDesc('sale_date')
            ->limit($limit)
            ->get()
            ->map(fn (PharmacySale $sale) => $this->event(
                key: "sale-{$sale->id}",
                kind: 'pharmacy',
                section: 'pharmacy',
                title: 'Pharmacy Purchase',
                at: $sale->sale_date,
                date: null,
                // Cancelled outranks how it was paid — a cancelled bill is not "paid".
                status: $sale->status === PharmacySale::CANCELLED ? PharmacySale::CANCELLED : $sale->payment_status,
                doctor: null,
                location: $sale->store?->location?->name ?? $sale->store?->name,
                detail: $sale->sale_number,
                count: (int) $sale->items_count,
                amount: (float) $sale->total_amount,
            ));
    }

    private function invoiceEvents(Customer $customer, ?array $allowed, int $limit): Collection
    {
        return $this->invoices($customer, $allowed)
            ->with(['doctor', 'location'])
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => $this->event(
                key: "invoice-{$invoice->id}",
                kind: 'invoice',
                section: 'billing',
                title: 'Invoice',
                at: $invoice->invoice_date,
                date: null,
                status: in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_DRAFT, Invoice::STATUS_REFUNDED], true)
                    ? $invoice->status
                    : $invoice->payment_status,
                doctor: $invoice->doctor?->name,
                location: $invoice->location?->name,
                detail: $invoice->invoice_number,
                amount: (float) $invoice->total_amount,
                link: "/billing/invoices/{$invoice->id}",
            ));
    }

    private function documentEvents(Customer $customer, bool $clinical, int $limit): Collection
    {
        return $this->documents($customer, $clinical)
            ->with('location')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (PatientDocument $document) => $this->event(
                key: "document-{$document->id}",
                kind: 'document',
                section: 'documents',
                title: 'Document',
                at: $document->created_at,
                date: null,
                status: null,
                doctor: null,
                location: $document->location?->name,
                detail: trim($document->title.' · '.DocumentCategories::name((string) $document->category), ' ·'),
            ));
    }

    /**
     * One row of the timeline, the same shape whichever module it came from.
     *
     * `at` is when it happened, to the minute where that is known. Where only
     * the day is known — a booking with no slot — `has_time` says so, and the
     * screen prints the date alone rather than a midnight nobody chose.
     *
     * @return array<string, mixed>
     */
    private function event(
        string $key,
        string $kind,
        string $section,
        string $title,
        ?Carbon $at,
        ?Carbon $date,
        ?string $status,
        ?string $doctor,
        ?string $location,
        ?string $detail,
        ?int $count = null,
        ?float $amount = null,
        ?string $link = null,
    ): array {
        $when = $at ?? $date?->copy()->startOfDay();

        return [
            'key' => $key,
            'kind' => $kind,
            'section' => $section,
            'title' => $title,
            'at' => $when?->toIso8601String(),
            'has_time' => $at !== null,
            'status' => $status,
            'doctor_name' => $doctor,
            'location_name' => $location,
            'detail' => $detail,
            'count' => $count,
            'amount' => $amount,
            'link' => $link,
        ];
    }

    /** The booked slot as a moment, or null for a walk-in with no slot. */
    private function slotTime(Appointment $visit): ?Carbon
    {
        if (! $visit->appointment_date || ! $visit->slot_at) {
            return null;
        }

        return Carbon::parse($visit->appointment_date->toDateString().' '.substr((string) $visit->slot_at, 0, 5));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow a query to the branches somebody may see.
     *
     * Null is every branch. An empty list is NO branch — head office — and
     * `whereIn` on `[0]` keeps that a refusal rather than matching everything.
     */
    private function atBranches(Builder $query, ?array $allowed, string $column = 'location_id'): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn($column), $allowed ?: [0]);
    }

    /**
     * Null when there is nothing to compare against — a rise from zero has
     * no percentage, and printing one is a number somebody would repeat.
     */
    private function change(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round((($now - $before) / $before) * 100) : null;
    }
}
