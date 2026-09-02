<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateCustomerRequest;
use App\Http\Resources\Tenant\CustomerResource;
use App\Models\Tenant\Customer;
use App\Models\Tenant\EntityFieldSetting;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Fields\FieldSchema;
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
    ) {
    }

    /**
     * The branch the signed-in person works at, or null for the owner, who
     * works across the whole network.
     */
    private function callersBranch(): ?int
    {
        return Auth::guard('web')->user()?->location_id;
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
        );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                // Phone first in spirit: the counter searches by number.
                searchable: ['name', 'phone', 'email', 'city'],
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
