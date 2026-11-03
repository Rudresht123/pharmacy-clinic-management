<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveQueueDisplayRequest;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\QueueDisplay;
use App\Services\Queue\QueueBoard;
use App\Services\Queue\QueueDisplays;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The televisions, for whoever sets them up.
 *
 * A new or rotated display's URL is in that one response and never again: only
 * its hash is kept. Previewing needs no URL, because somebody signed in is
 * reading the same state the screen would.
 *
 * @group Tenant > Appointments & OPD
 */
class QueueDisplayController extends BaseApiController
{
    public function __construct(
        private readonly QueueDisplays $displays,
        private readonly QueueBoard $board,
        private readonly TenantBranchAccess $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = $this->branches->allowed($request->user());

        $rows = QueueDisplay::query()
            ->with(['location', 'department', 'doctors'])
            ->when($allowed !== null, fn ($query) => $query->whereIn('location_id', $allowed))
            ->orderBy('location_id')
            ->orderBy('display_name')
            ->get()
            ->map(fn (QueueDisplay $display) => $this->displays->summary($display))
            ->all();

        return $this->ok($rows);
    }

    /** What the form offers: the branches here, and their doctors and departments. */
    public function options(Request $request): JsonResponse
    {
        $allowed = $this->branches->allowed($request->user());

        $locations = Location::query()
            ->active()
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok([
            'locations' => $locations->map(fn (Location $location) => [
                'id' => (int) $location->id,
                'name' => $location->name,
                'doctors' => $this->board->postedAt((int) $location->id)
                    ->map(fn (Doctor $doctor) => [
                        'id' => (int) $doctor->id,
                        'name' => $doctor->name,
                        'department_id' => $doctor->department_id,
                        'department' => $doctor->department?->name ?? $doctor->specialisation,
                    ])
                    ->values()
                    ->all(),
            ])->all(),
            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (Department $department) => [
                    'id' => (int) $department->id,
                    'name' => $department->path(),
                ])
                ->all(),
        ]);
    }

    public function store(SaveQueueDisplayRequest $request): JsonResponse
    {
        try {
            $made = $this->displays->create(
                $request->attributes->get('tenant.organization'),
                $request->validated(),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->created([
            ...$this->displays->summary($made['display']),
            'url' => $this->displays->url($made['token']),
        ], 'Display created. Copy its link now: it will not be shown again.');
    }

    public function show(Request $request, int $display): JsonResponse
    {
        return $this->ok($this->displays->summary($this->findMine($request, $display)));
    }

    public function update(SaveQueueDisplayRequest $request, int $display): JsonResponse
    {
        try {
            $updated = $this->displays->update($this->findMine($request, $display), $request->validated());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->displays->summary($updated->fresh()), 'Display saved');
    }

    public function destroy(Request $request, int $display): JsonResponse
    {
        $this->findMine($request, $display)->delete();

        return $this->ok(null, 'Display removed');
    }

    /** The same screen the television reads, without its secret. */
    public function preview(Request $request, int $display): JsonResponse
    {
        return $this->ok($this->displays->state(
            $this->findMine($request, $display),
            $request->attributes->get('tenant.organization'),
            withChannels: false,
        ));
    }

    public function rotate(Request $request, int $display): JsonResponse
    {
        $found = $this->findMine($request, $display);

        $token = $this->displays->rotate($request->attributes->get('tenant.organization'), $found);

        return $this->ok([
            ...$this->displays->summary($found->fresh()),
            'url' => $this->displays->url($token),
        ], 'New link made. The old one has stopped working: copy this one now.');
    }

    public function revoke(Request $request, int $display): JsonResponse
    {
        return $this->ok(
            $this->displays->summary($this->displays->revoke($this->findMine($request, $display))),
            'Display access revoked',
        );
    }

    /** A display id is just a number until somebody checks it is at their branch. */
    private function findMine(Request $request, int $id): QueueDisplay
    {
        $display = QueueDisplay::query()->findOrFail($id);

        if (! $this->branches->canUse($request->user(), (int) $display->location_id)) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return $display;
    }
}
