<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Platform\AuditLogResource;
use App\Models\Platform\Organization;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What has been done on the platform side.
 *
 * Read-only, and there is no write endpoint anywhere: rows arrive from the
 * RecordsHistory trait on the models themselves, so a change made from a
 * console command or a queued job is recorded exactly like one made through
 * the API.
 */
class AuditController extends BaseApiController
{
    use HandlesTableQueries;

    /**
     * The whole log, newest first.
     *
     * Answers "who did what, when" across the platform. One organization's
     * own story is a different question and has its own endpoint below.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlatformAuditLog::query()
            ->when(
                $request->filled('entity_type'),
                fn (Builder $q) => $q->where('entity_type', $request->string('entity_type'))
            )
            ->when(
                $request->filled('action'),
                fn (Builder $q) => $q->where('action', $request->string('action'))
            )
            ->when(
                $request->filled('actor'),
                fn (Builder $q) => $q->where('actor_name', 'ILIKE', '%'.$request->string('actor').'%')
            )
            ->when(
                $request->filled('since'),
                fn (Builder $q) => $q->where('created_at', '>=', $request->date('since'))
            );

        $page = $this->tableQuery(
            $query,
            $request,
            // The label is what somebody actually remembers about a change.
            searchable: ['entity_label', 'actor_name', 'entity_type'],
            sortable: ['created_at', 'action', 'entity_type'],
            defaultSort: 'created_at',
        );

        return $this->paginated($page, AuditLogResource::class);
    }

    /**
     * Everything that has happened to one organization.
     *
     * Scoped by `organization_id` rather than by entity, so assigning a
     * module — which writes a row about the binding, not about the
     * organization — appears here alongside a rename.
     */
    public function forOrganization(Request $request, Organization $organization): JsonResponse
    {
        $page = $this->tableQuery(
            PlatformAuditLog::where('organization_id', $organization->id),
            $request,
            searchable: ['entity_label', 'actor_name', 'entity_type'],
            sortable: ['created_at', 'action'],
            defaultSort: 'created_at',
        );

        return $this->paginated($page, AuditLogResource::class);
    }

    /**
     * The values the filters offer, taken from what is actually in the log.
     *
     * A hardcoded list would offer entity types nobody has ever touched and
     * miss the one somebody is looking for.
     */
    public function filters(): JsonResponse
    {
        return $this->ok([
            'entity_types' => PlatformAuditLog::query()
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type'),

            'actions' => PlatformAuditLog::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
