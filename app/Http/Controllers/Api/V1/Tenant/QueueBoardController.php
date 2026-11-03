<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Broadcasting\DoctorQueueChannel;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\QueueEvent;
use App\Services\Permissions\Permission;
use App\Services\Queue\QueueBoard;
use App\Services\Queue\QueueClock;
use App\Services\Queue\QueueOrder;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the live queue: every doctor at a branch, or one of them in full.
 *
 * Each answer names the channels to listen on, because a client never builds a
 * channel name for itself (see SessionPayload).
 *
 * @group Tenant > Appointments & OPD
 */
class QueueBoardController extends BaseApiController
{
    public function __construct(
        private readonly QueueBoard $board,
        private readonly TenantBranchAccess $branches,
        private readonly Permission $permission,
    ) {}

    /** The reception board: all doctors, one card each. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $locationId = (int) $data['location_id'];
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        $this->assertBranch($locationId);

        $date = QueueClock::today();
        $doctors = $this->board->roster($locationId, $date, $departmentId);

        return $this->ok([
            'date' => $date,
            'location_id' => $locationId,
            'cards' => $this->board->cards($doctors, $locationId, $date, forStaff: true),
            'channels' => $doctors
                ->map(fn (Doctor $doctor) => $this->channel($request, $locationId, (int) $doctor->id))
                ->values()
                ->all(),

            // For the filter, and for choosing who to move a patient to.
            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (Department $department) => [
                    'id' => (int) $department->id,
                    'name' => $department->name,
                    'parent_id' => $department->parent_id,
                ])
                ->all(),
            'doctors' => $this->board->postedAt($locationId)
                ->map(fn (Doctor $doctor) => [
                    'id' => (int) $doctor->id,
                    'name' => $doctor->name,
                    'department' => $doctor->department?->name ?? $doctor->specialisation,
                ])
                ->values()
                ->all(),

            'urgent_head_start_minutes' => QueueOrder::URGENT_HEAD_START_MINUTES,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** One doctor's whole day. A doctor reads their own; the desk reads anybody's. */
    public function doctor(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->readableLocation($request, $doctor);

        return $this->ok([
            ...$this->board->detail($doctor, $locationId, QueueClock::today()),
            'location_id' => $locationId,
            'channel' => $this->channel($request, $locationId, (int) $doctor->id),
        ]);
    }

    /** What happened in this queue, newest first. */
    public function history(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->readableLocation($request, $doctor);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $rows = QueueEvent::query()
            ->with(['actor:id,name', 'appointment:id,customer_id', 'appointment.customer:id,name'])
            ->where('doctor_id', $doctor->id)
            ->where('location_id', $locationId)
            ->where('queue_date', $request->input('date', QueueClock::today()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (QueueEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'old_status' => $event->old_status,
                'new_status' => $event->new_status,
                'token' => $event->metadata['token'] ?? null,
                'customer_name' => $event->appointment?->customer?->name,
                'actor_name' => $event->actor?->name,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return $this->ok($rows);
    }

    /**
     * The branch asked about, once this person may read this queue there.
     *
     * Whoever holds the board reads any doctor's. Somebody holding only their
     * own screens reads their own, and asking for a colleague's is refused.
     */
    private function readableLocation(Request $request, Doctor $doctor): int
    {
        $request->validate(['location_id' => ['required', 'integer']]);

        $locationId = (int) $request->input('location_id');

        $this->assertBranch($locationId);

        $user = $request->user();
        $organization = $request->attributes->get('tenant.organization');

        if ((int) $user->userable_id === (int) $doctor->id && $user->userable_type === Doctor::class) {
            return $locationId;
        }

        if ($organization instanceof Organization && $this->permission->allows($organization, $user, 'queue.board', $locationId)) {
            return $locationId;
        }

        abort(403, 'That queue belongs to another doctor.');
    }

    /** The doctor's channel, as Echo's `private-` prefix names it. */
    private function channel(Request $request, int $locationId, int $doctorId): string
    {
        return 'private-'.DoctorQueueChannel::name(
            $request->attributes->get('tenant.organization')->uuid,
            $locationId,
            $doctorId,
        );
    }

    private function assertBranch(int $locationId): void
    {
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }
    }
}
