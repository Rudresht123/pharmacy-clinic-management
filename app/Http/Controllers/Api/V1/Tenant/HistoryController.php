<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\ActivityLogResource;
use App\Models\Tenant\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this organization's own people have changed.
 *
 * Reads the tenant's own `activity_logs`, in the tenant's own database. It
 * can never show another organization's activity because there is no other
 * organization's data in this connection to show — the isolation is
 * physical, not a where clause somebody has to remember.
 *
 * The platform's log is a separate table in a separate database with its own
 * endpoint: a super administrator's actions on the platform are not this
 * organization's business, and this organization's records are not the
 * platform log's.
 *
 * Read-only. Rows arrive from the RecordsHistory trait on the models, so a
 * change made by a console command is recorded exactly like one made here.
 */
class HistoryController extends BaseApiController
{
    use HandlesTableQueries;

    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::on('organization')
            ->with('user')
            ->when(
                $request->filled('entity_type'),
                fn (Builder $q) => $q->where('entity_type', $request->string('entity_type'))
            )
            ->when(
                $request->filled('event'),
                fn (Builder $q) => $q->where('event', $request->string('event'))
            )
            ->when(
                $request->filled('actor'),
                fn (Builder $q) => $q->where('actor_name', 'ILIKE', '%'.$request->string('actor').'%')
            )
            ->when(
                $request->filled('since'),
                fn (Builder $q) => $q->where('created_at', '>=', $request->date('since')->setTimezone(config('app.timezone')))
            );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['entity_label', 'actor_name', 'entity_type'],
                sortable: ['created_at', 'event', 'entity_type'],
                defaultSort: 'created_at',
            ),
            ActivityLogResource::class,
        );
    }

    /**
     * One record's own story.
     *
     * Open to anybody signed in, unlike the whole log: somebody who can
     * already see a customer can reasonably ask who last changed their phone
     * number. The entity type comes from the URL and is matched against what
     * is in the log rather than a hardcoded list, so a newly tracked model
     * needs no change here.
     */
    public function forRecord(Request $request, string $entity, int $id): JsonResponse
    {
        $query = ActivityLog::on('organization')
            ->with('user')
            ->where('entity_type', $entity)
            ->where('entity_id', $id);

        return $this->paginated(
            $this->tableQuery($query, $request, sortable: ['created_at'], defaultSort: 'created_at'),
            ActivityLogResource::class,
        );
    }

    /** The filter values that actually occur, not a hardcoded list. */
    public function filters(): JsonResponse
    {
        return $this->ok([
            'entity_types' => ActivityLog::on('organization')
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type'),

            'actions' => ActivityLog::on('organization')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
        ]);
    }
}
