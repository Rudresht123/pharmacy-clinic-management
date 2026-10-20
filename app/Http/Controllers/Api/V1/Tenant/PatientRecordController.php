<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\CustomerResource;
use App\Http\Resources\Tenant\PatientVisitResource;
use App\Http\Resources\Tenant\PharmacySaleResource;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Exports\Column;
use App\Services\Exports\Datasets\Patients\PatientRegister;
use App\Services\Exports\ExportAccess;
use App\Services\Exports\ExportWriter;
use App\Services\Tenant\PatientRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patient Records — one patient's history from every module, in one place.
 *
 * Reads only. Nothing here writes a record or keeps a copy of one: the
 * register is the customers module's (through its repository), and each
 * section is that module's own table read through its own model. Sections a
 * module already lists per patient — prescriptions, lab orders, invoices,
 * payments, documents — are not served here at all; the screen calls those
 * modules' own endpoints with the patient's id. This controller serves only
 * what no module answered per patient: the list with each patient's last
 * visit, the profile's summary and timeline, and a patient's appointments and
 * pharmacy bills.
 *
 * Who may see which section, and at which branches, is App\Services\Tenant\
 * PatientRecord's to decide — the same answer for the summary's counts as for
 * the lists behind them, so a tab never promises twelve rows and opens on three.
 *
 * @group Tenant > Patient records
 */
class PatientRecordController extends BaseApiController
{
    use HandlesTableQueries;

    /** What the list's search box reaches — the register's own columns. */
    private const SEARCHABLE = ['code', 'name', 'phone', 'email'];

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly PatientRecord $records,
    ) {}

    /**
     * The register, with when each patient was last seen.
     *
     * The register itself is the organization's — the same rows, filters and
     * search as the Patients screen. Only the last visit is narrowed to the
     * caller's branches, and only computed when they may read visits at all.
     */
    public function index(Request $request): JsonResponse
    {
        [$organization, $user] = $this->caller($request);

        [$query, $visits] = $this->register($request, $organization, $user);

        /*
         * Most recently seen first, by default — who somebody is looking for
         * is usually who was in this week. Patients never seen go last rather
         * than first, which is where Postgres would put them on its own.
         */
        if ($visits && $request->query('sort', 'last_visit') === 'last_visit') {
            $direction = strtolower((string) $request->query('direction')) === 'asc' ? 'asc' : 'desc';

            $this->applySearch($query, $request, self::SEARCHABLE);

            $page = $query
                ->orderByRaw("last_visit_on {$direction} NULLS LAST")
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($this->resolvePerPage($request))
                ->withQueryString();
        } else {
            $page = $this->tableQuery(
                $query,
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['name', 'code', 'created_at'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            );
        }

        return $this->paginated($page, CustomerResource::class);
    }

    /**
     * The register as a file — Excel, CSV or PDF — with the list's own search
     * and filters, so what downloads is exactly what the list holds: filtered,
     * the patients that match; unfiltered, every patient.
     *
     * The Export Center's own register underneath (PatientRegister, written
     * by ExportWriter): the same columns, less any this person's roles hide
     * there, so a column taken away from a role is gone from both buttons.
     * Only the rows are this screen's — its organization-wide list, as on
     * screen.
     */
    public function export(
        Request $request,
        PatientRegister $register,
        ExportAccess $access,
        ExportWriter $writer,
    ): Response|StreamedResponse|BinaryFileResponse {
        $request->validate(['format' => ['required', Rule::in(ExportWriter::FORMATS)]]);

        [$organization, $user] = $this->caller($request);
        [$query, $visits] = $this->register($request, $organization, $user);

        $this->applySearch($query, $request, self::SEARCHABLE);

        // Counted before ordering: the order names a column the count does not select.
        $total = (clone $query)->count();

        $this->orderRegister($query, $request, $visits);

        // A file has no use for the list's photos.
        $query->without('photograph');

        // Last visit only for somebody who may read visits — the list's own rule.
        $columns = array_values(array_filter(
            $access->columns($user, $register, $access->branches($organization, $user, 'patients')),
            fn (Column $column) => $visits || $column->key !== 'last_visit',
        ));

        abort_if($columns === [], 403, 'You may not export the patient register.');

        return $writer->download(
            (string) $request->input('format'),
            'patients',
            $columns,
            $query,
            fn (Customer $patient) => $register->row($patient),
            $total,
            [
                'organization' => (string) $organization->organization_name,
                'title' => 'Patient Register',
                'noun' => 'patients',
                'by' => (string) $user->name,
                'filters' => $this->filterSummary($request),
            ],
            muted: fn (Customer $patient) => ! $patient->is_active,
        );
    }

    /** The cards above the list, and the branches its filter offers. */
    public function stats(Request $request): JsonResponse
    {
        [$organization, $user] = $this->caller($request);

        return $this->ok([
            'cards' => $this->records->cards($organization, $user),
            'branches' => $this->records->branchOptions($user),
        ]);
    }

    /** One patient: who they are, a count on every tab, and what happened lately. */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        [$organization, $user] = $this->caller($request);

        return $this->ok([
            'patient' => CustomerResource::make($customer->loadMissing(['registeredLocation', 'photograph'])),
            ...$this->records->summary($organization, $user, $customer),
        ]);
    }

    /** Everything, newest first, across modules — the Recent Activity card's "View all". */
    public function timeline(Request $request, Customer $customer): JsonResponse
    {
        [$organization, $user] = $this->caller($request);

        $limit = max(1, min((int) $request->query('limit', 50), 200));

        return $this->ok($this->records->timeline($organization, $user, $customer, $limit));
    }

    /**
     * A patient's appointments, with what was written up at each.
     *
     * `view=visits` is the ones they came to — the OPD history; `upcoming` is
     * what is still booked, soonest first; anything else is every booking.
     */
    public function appointments(Request $request, Customer $customer): JsonResponse
    {
        [, $user] = $this->caller($request);

        $allowed = $this->records->allowed($user);
        $view = $request->string('view')->toString();

        $query = match ($view) {
            'visits' => $this->records->visits($customer, $allowed),
            'upcoming' => $this->records->appointments($customer, $allowed)
                ->where('status', Appointment::STATUS_BOOKED)
                ->whereDate('appointment_date', '>=', Carbon::today()),
            default => $this->records->appointments($customer, $allowed),
        };

        $direction = $view === 'upcoming' ? 'asc' : 'desc';

        $page = $query
            ->with(['doctor', 'location', 'consultation.livePrescription.items'])
            ->when(
                $request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')),
            )
            ->orderBy('appointment_date', $direction)
            ->orderBy('slot_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return $this->paginated($page, PatientVisitResource::class);
    }

    /** A patient's pharmacy bills, newest first, with what was on each. */
    public function pharmacy(Request $request, Customer $customer): JsonResponse
    {
        [, $user] = $this->caller($request);

        $query = $this->records->pharmacySales($customer, $this->records->allowed($user))
            ->with(['store', 'customer', 'doctor', 'items'])
            ->withCount('items')
            ->when(
                $request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')),
            );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['sale_number'],
                sortable: ['sale_date', 'total_amount'],
                defaultSort: 'sale_date',
            ),
            PharmacySaleResource::class,
        );
    }

    /** @return array{0: Organization, 1: User} */
    /**
     * The register's query — filters, and each patient's last visit where the
     * caller may read visits — shared by the list and its export, so the two
     * can never disagree about who is in it.
     *
     * @return array{0: Builder, 1: bool} the query, and whether visits are in it
     */
    private function register(Request $request, Organization $organization, User $user): array
    {
        $visits = $this->records->sections($organization, $user)['visits'];

        $query = $this->customers->listing(
            status: $request->string('status')->toString() ?: null,
            registeredLocationId: $request->filled('registered_location_id')
                ? (int) $request->input('registered_location_id')
                : null,
            filters: $request->only(['gender']),
        );

        if ($visits) {
            $query->addSelect([
                'last_visit_on' => $this->records->lastVisitSubquery($this->records->allowed($user)),
            ]);
        }

        return [$query, $visits];
    }

    /** The list's order, for a file of every page: last seen first, or the column asked for. */
    private function orderRegister(Builder $query, Request $request, bool $visits): void
    {
        $direction = strtolower((string) $request->query('direction')) === 'desc' ? 'desc' : 'asc';

        if ($visits && $request->query('sort', 'last_visit') === 'last_visit') {
            $direction = strtolower((string) $request->query('direction')) === 'asc' ? 'asc' : 'desc';
            $query->orderByRaw("last_visit_on {$direction} NULLS LAST");
        } else {
            $sort = in_array($request->query('sort'), ['name', 'code', 'created_at'], true) ? $request->query('sort') : 'name';
            $query->orderBy($sort, $direction);
        }

        $query->orderBy('name')->orderBy('id');
    }

    /** @return list<string> what narrowed the list, in words, for the PDF's heading */
    private function filterSummary(Request $request): array
    {
        $branch = $request->filled('registered_location_id')
            ? Location::query()->whereKey($request->integer('registered_location_id'))->value('name')
            : null;

        return array_values(array_filter([
            $request->filled('search') ? 'Search “'.trim((string) $request->input('search')).'”' : null,
            $branch ? "Registered at {$branch}" : null,
            $request->filled('gender') ? ucfirst((string) $request->input('gender')) : null,
            $request->filled('status') ? ucfirst((string) $request->input('status')) : null,
        ]));
    }

    private function caller(Request $request): array
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        abort_unless($organization instanceof Organization && $user instanceof User, 403);

        return [$organization, $user];
    }
}
