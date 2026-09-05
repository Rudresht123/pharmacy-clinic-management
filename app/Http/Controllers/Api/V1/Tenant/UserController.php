<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreTenantUserRequest;
use App\Http\Requests\Api\V1\Tenant\SaveUserBranchesRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantUserRequest;
use App\Http\Resources\Tenant\UserResource;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\User;
use App\Repositories\Tenant\Contracts\TenantUserRepositoryInterface;
use App\Services\Fields\FieldSchema;
use App\Services\Permissions\StaffScope;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Fields\UserFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The people inside one organization. Owner-only throughout — deciding who
 * can sign in is not something a staff account should be able to change.
 */
class UserController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly TenantUserRepositoryInterface $users,
        private readonly StaffScope $scope,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The field definitions the form and table render from — the code
     * registry with this organization's own preferences applied, plus any
     * fields it added itself.
     */
    public function fields(FieldSchema $schema): JsonResponse
    {
        return $this->ok(
            $schema->for(EntityFieldSetting::ENTITY_USER, UserFields::all())
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->users->listing(
            role: $request->string('role')->toString() ?: null,
            activeOnly: $request->boolean('active_only'),
        );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['name', 'email'],
                sortable: ['name', 'email', 'role', 'is_active', 'last_login_at', 'created_at'],
                defaultSort: 'created_at',
            ),
            UserResource::class,
        );
    }

    public function show(User $user): JsonResponse
    {
        $this->mustReach($user);

        return $this->ok(UserResource::make(
            $user->load(['permissionRole', 'memberships.location', 'memberships.role'])
        ));
    }

    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $request->boolean('is_active', true);

        $user = $this->users->create($data);

        return $this->created(UserResource::make($user), 'User added successfully.');
    }

    public function update(UpdateTenantUserRequest $request, User $user): JsonResponse
    {
        $this->mustReach($user);

        $data = $request->validated();

        // Blank means "leave the password alone" rather than "clear it".
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $data['is_active'] = $request->boolean('is_active', $user->is_active);

        $updated = $this->users->update($user, $data);

        return $this->ok(UserResource::make($updated), 'User updated successfully.');
    }

    public function destroy(User $user): JsonResponse
    {
        $this->mustReach($user);

        $signedIn = Auth::guard('web')->user();

        if ($signedIn && $signedIn->getKey() === $user->getKey()) {
            return $this->fail('You cannot remove your own account.', 422);
        }

        /*
         * Same reasoning as demoting the last owner in
         * UpdateTenantUserRequest: an organization left with nobody who can
         * manage it cannot fix that from the inside.
         */
        if ($user->isOwner() && $this->users->otherActiveOwnerCount($user) === 0) {
            return $this->fail(
                'This is the only active owner. Make somebody else an owner first.',
                422
            );
        }

        $this->users->delete($user);

        return $this->noContent('User removed successfully.');
    }

    /**
     * Where somebody works — the whole set, replaced in one write.
     *
     * Separate from `update`, which is about who they are. Moving somebody
     * between branches is organization-level work, and folding it into the
     * ordinary edit would have handed it to every branch manager holding
     * `people.edit`.
     */
    public function branches(SaveUserBranchesRequest $request, User $user): JsonResponse
    {
        $this->mustReach($user);

        if ($user->isOwner()) {
            abort(422, 'An owner works across every branch and is not assigned to one.');
        }

        $rows = collect($request->validated()['branches']);

        $actor = Auth::guard('web')->user();
        $reach = $this->branches->allowed($actor);

        DB::connection('organization')->transaction(function () use ($rows, $user, $reach) {
            $keep = $rows->pluck('location_id')->map(fn ($id) => (int) $id);

            /*
             * Only memberships the caller can actually act on are replaced.
             *
             * The payload is the whole set AS FAR AS THEY CAN SEE IT. A
             * Lucknow manager sending "Lucknow only" is saying nothing about
             * Delhi, and deleting the Delhi membership because it was absent
             * would let them quietly remove somebody from a branch they have
             * no business touching. Null reach — the owner, head office — is
             * everywhere, so for them this is the whole set.
             */
            $removable = $user->memberships()
                ->whereNotIn('location_id', $keep->all() ?: [0]);

            if ($reach !== null) {
                $removable->whereIn('location_id', $reach ?: [0]);
            }

            $removable->delete();

            foreach ($rows as $row) {
                $user->memberships()->updateOrCreate(
                    ['location_id' => (int) $row['location_id']],
                    [
                        'role_id' => $row['role_id'] ?? null,
                        'is_primary' => (bool) ($row['is_primary'] ?? false),
                    ],
                );
            }
        });

        return $this->ok(
            UserResource::make($user->fresh()->load(['memberships.location', 'memberships.role'])),
            'Branches updated successfully.'
        );
    }

    /**
     * Refuse a record outside the caller's reach.
     *
     * 404 rather than 403, deliberately. `permission:people.view` has already
     * confirmed the caller may administer staff, so the only thing left to say
     * is whether THIS person is theirs to administer — and answering 403 would
     * confirm the id exists to somebody who may not see it. A record they
     * cannot reach should be indistinguishable from one that is not there.
     */
    private function mustReach(User $user): void
    {
        if (! $this->scope->canManage(Auth::guard('web')->user(), $user)) {
            abort(404, 'Resource not found.');
        }
    }
}
