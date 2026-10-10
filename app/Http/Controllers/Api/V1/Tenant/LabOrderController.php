<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveLabOrderRequest;
use App\Http\Resources\Tenant\LabOrderItemResource;
use App\Http\Resources\Tenant\LabOrderResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use App\Models\Tenant\User;
use App\Services\Billing\BillingConflict;
use App\Services\Billing\BillingTriggerResolver;
use App\Services\Laboratory\LabOrders;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lab orders — raising them, working them, finishing them.
 *
 * THREE AUDIENCES, ONE CONTROLLER, and they are told apart by capability
 * rather than by route prefix:
 *
 *   the DOCTOR      orders tests against their own visit (`laboratory.order`,
 *                   plus the Policy's check that the visit is theirs)
 *   the TECHNICIAN  takes orders on, enters results, signs them off
 *                   (`laboratory.process` / `.complete`)
 *   everybody else  with `laboratory.view` reads — the desk answering "are
 *                   my results back", the doctor reading what came in
 *
 * The route holds the capability at the acting branch; LabOrderPolicy asks
 * about the ORDER's own branch. LabOrders decides whether its state allows
 * the change.
 *
 * @group Tenant > Laboratory
 */
class LabOrderController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    private const WITH = ['items', 'patient', 'doctor', 'location', 'appointment', 'starter', 'completer'];

    public function __construct(
        private readonly LabOrders $orders,
        private readonly TenantBranchAccess $branches,
        private readonly BillingTriggerResolver $billing,
        private readonly Permission $permission,
    ) {}

    /** What an order is loaded with for this caller. */
    private function with(): array
    {
        return [...self::WITH, ...$this->billingRelations()];
    }

    /**
     * The order's bill, for somebody who handles money.
     *
     * A technician entering results has no need to read what the patient
     * owes; somebody who may see invoices or take payment does, and the lab
     * counter is one of those places.
     *
     * @return list<string>
     */
    private function billingRelations(): array
    {
        $organization = request()->attributes->get('tenant.organization');
        $user = Auth::guard('web')->user();

        if (! $organization || ! $user instanceof User) {
            return [];
        }

        $handlesMoney = $this->permission->allows($organization, $user, 'billing.view')
            || $this->permission->allows($organization, $user, 'billing.collect_payment');

        return $handlesMoney ? ['liveInvoice'] : [];
    }

    /**
     * Bill this order now, at the lab counter — whatever the lab's timing.
     *
     * Drawing a bill is `billing.create`; the order has to be one the caller
     * may see. Answers with the order's bill if it already has one, so a
     * double-click raises one invoice.
     */
    public function bill(LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('view', $order);

        try {
            $invoice = $this->billing->billLabOrder($order);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($invoice === null) {
            return $this->fail('Nothing on this order is priced, so there is nothing to bill.', 422);
        }

        return $this->ok(
            LabOrderResource::make($order->fresh()->load($this->with())),
            "{$invoice->invoice_number} is ready for payment",
        );
    }

    /**
     * The worklist, at the branches the caller works at.
     *
     * `outstanding=1` is the technician's default view: everything ordered or
     * in hand, and nothing signed off. Two statuses rather than one, which a
     * plain `status=` filter cannot express — and the distinction matters,
     * because an order somebody is already working is not one to pick up.
     */
    public function index(Request $request): JsonResponse
    {
        $mine = $this->branches->allowed(Auth::guard('web')->user());

        $query = LabOrder::query()
            ->with(['items', 'patient', 'doctor', 'location', 'appointment', ...$this->billingRelations()])
            ->when($mine !== null, fn (Builder $q) => $q->whereIn('location_id', $mine ?: [0]))
            ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer_id'), fn (Builder $q) => $q->where('customer_id', (int) $request->input('customer_id')))
            ->when($request->filled('doctor_id'), fn (Builder $q) => $q->where('doctor_id', (int) $request->input('doctor_id')))
            ->when($request->filled('location_id'), fn (Builder $q) => $q->where('location_id', (int) $request->input('location_id')));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['order_number'],
                sortable: ['order_date', 'order_number', 'status', 'created_at'],
                defaultSort: 'created_at',
            ),
            LabOrderResource::class,
        );
    }

    public function show(LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('view', $order);

        return $this->ok(LabOrderResource::make($order->load($this->with())));
    }

    /** Every order raised at one visit — what the consultation screen reads. */
    public function forAppointment(Appointment $appointment): JsonResponse
    {
        $this->authorizeTenant('viewVisit', [LabOrder::class, $appointment]);

        return $this->ok([
            'appointment_id' => $appointment->id,
            'orders' => LabOrderResource::collection(
                $appointment->labOrders()->with($this->with())->latest('id')->get()
            ),
        ]);
    }

    public function store(SaveLabOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $appointment = Appointment::query()->findOrFail($data['appointment_id']);

        $this->authorizeTenant('create', [LabOrder::class, $appointment]);

        $order = $this->orders->create($appointment, $data);

        return $this->created(
            LabOrderResource::make($order->load($this->with())),
            "{$order->order_number} ordered",
        );
    }

    /** Change a pending order's tests. Once the bench has it, raise another. */
    public function update(SaveLabOrderRequest $request, LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('update', $order);

        $updated = $this->orders->update($order, $request->validated());

        return $this->ok(LabOrderResource::make($updated->load($this->with())), 'Lab order saved');
    }

    /** A technician picks it up. */
    public function process(Request $request, LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('process', $order);

        $started = $this->orders->start($order, $request->user());

        return $this->ok(
            LabOrderResource::make($started->load($this->with())),
            "{$started->order_number} in progress",
        );
    }

    /**
     * Enter one reading.
     *
     * Per line, because results come back at their own times — making the
     * bench hold a finished CBC until tomorrow's LFT arrives is how results
     * end up written on paper instead.
     */
    public function record(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeTenant('process', $order);

        $this->assertLineBelongs($order, $item);

        $validated = $request->validate([
            'result_value' => ['required', 'string', 'max:191'],
            'result_unit' => ['nullable', 'string', 'max:40'],
            'reference_range' => ['nullable', 'string', 'max:100'],
            'is_abnormal' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->ok(
            LabOrderItemResource::make($this->orders->record($item, $validated)),
            "{$item->test_name} recorded",
        );
    }

    /** A test that will not be done — the sample was spoiled, or the patient left. */
    public function cancelLine(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeTenant('process', $order);

        $this->assertLineBelongs($order, $item);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->ok(
            LabOrderItemResource::make($this->orders->cancelItem($item, $validated['reason'])),
            "{$item->test_name} cancelled",
        );
    }

    /**
     * Sign the order off.
     *
     * The visit is asked again straight after, inside the same transaction,
     * and the response carries whatever it decided — so the technician's
     * screen can say "visit completed" rather than leaving somebody to
     * wonder whether the patient can go home.
     */
    public function complete(Request $request, LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('complete', $order);

        $completed = $this->orders->complete($order, $request->user());

        return $this->ok(
            LabOrderResource::make($completed->load($this->with())),
            "{$completed->order_number} completed",
        );
    }

    public function cancel(Request $request, LabOrder $order): JsonResponse
    {
        $this->authorizeTenant('complete', $order);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->orders->cancel($order, $validated['reason'], $request->user());

        return $this->ok(
            LabOrderResource::make($cancelled->load($this->with())),
            "{$cancelled->order_number} cancelled",
        );
    }

    /**
     * A line id from the URL is just a number until somebody checks it.
     *
     * Without this, a technician authorised on their own branch's order could
     * record a result against a line belonging to another branch's — the
     * Policy was asked about the order, and the line came from elsewhere.
     */
    private function assertLineBelongs(LabOrder $order, LabOrderItem $item): void
    {
        if ((int) $item->lab_order_id !== (int) $order->id) {
            abort(404, 'That test is not on this order.');
        }
    }
}
