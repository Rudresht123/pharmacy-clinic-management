<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\StockMovementResource;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A store's ledger, newest first. Read-only: nothing here, or anywhere,
 * edits a movement.
 */
class StockMovementController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $query = $this->filtered($request, $store)->with(['medicine', 'batch']);

        // Free text over the things a receipt is chased by — the medicine,
        // its batch, or the GRN/reference a receiving clerk was handed.
        // Generic to the base table's own columns via HandlesTableQueries;
        // the medicine and batch names live on related tables, so those need
        // their own whereHas rather than the trait's plain column list.
        $term = trim((string) $request->query('search', ''));

        if ($term !== '') {
            $pattern = '%'.$term.'%';

            $query->where(function (Builder $outer) use ($pattern) {
                $outer
                    ->where('notes', 'ILIKE', $pattern)
                    ->orWhere('reason', 'ILIKE', $pattern)
                    ->orWhereHas('medicine', fn (Builder $q) => $q
                        ->where('brand_name', 'ILIKE', $pattern)
                        ->orWhere('generic_name', 'ILIKE', $pattern))
                    ->orWhereHas('batch', fn (Builder $q) => $q->where('batch_number', 'ILIKE', $pattern));
            });
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                sortable: ['movement_date', 'id'],
                defaultSort: 'id',
            ),
            StockMovementResource::class,
        );
    }

    /**
     * The four headline figures above the ledger: how many entries, how much
     * moved in, how much moved out, and the net of the two — over the same
     * filters as the table, plus the same window one period earlier so the
     * screen can say "+12%" against something.
     */
    public function summary(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $current = $this->totals($this->filtered($request, $store));
        $previous = $this->totals($this->filtered($request, $store, previousPeriod: true));

        return $this->ok([
            ...$current,
            'previous' => $previous,
        ]);
    }

    /**
     * @return array{count: int, added: int, deducted: int, net: int}
     */
    private function totals(Builder $query): array
    {
        $added = (int) (clone $query)->where('quantity', '>', 0)->sum('quantity');
        $deducted = (int) (clone $query)->where('quantity', '<', 0)->sum('quantity');

        return [
            'count' => (int) (clone $query)->count(),
            'added' => $added,
            // Reported as a positive figure — "how much left", not "by how
            // much did the signed total move".
            'deducted' => abs($deducted),
            'net' => $added + $deducted,
        ];
    }

    /**
     * The same scope `index()` reads by, minus free-text search — the
     * summary answers "how much, filtered this way", not "how much of what
     * this search term happened to match".
     *
     * @param  bool  $previousPeriod  Shift the date window back by its own
     *                                length instead of using it as given —
     *                                the "previous period" the deltas are
     *                                measured against.
     */
    private function filtered(Request $request, PharmacyStore $store, bool $previousPeriod = false): Builder
    {
        [$from, $to] = $this->window($request, $previousPeriod);

        return StockMovement::query()
            ->where('pharmacy_store_id', $store->id)
            ->when($request->filled('medicine_id'), fn (Builder $q) => $q->where('medicine_id', (int) $request->input('medicine_id')))
            ->when($request->filled('batch_id'), fn (Builder $q) => $q->where('medicine_batch_id', (int) $request->input('batch_id')))
            ->when($request->filled('movement_type'), fn (Builder $q) => $q->where('movement_type', $request->string('movement_type')->toString()))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('movement_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('movement_date', '<=', $to));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function window(Request $request, bool $previousPeriod): array
    {
        $from = $request->filled('from') ? $request->date('from') : null;
        $to = $request->filled('to') ? $request->date('to') : null;

        if (! $previousPeriod || $from === null || $to === null) {
            return [$from?->toDateString(), $to?->toDateString()];
        }

        $length = $from->diffInDays($to) + 1;
        $previousTo = (clone $from)->subDay();
        $previousFrom = (clone $previousTo)->subDays($length - 1);

        return [$previousFrom->toDateString(), $previousTo->toDateString()];
    }
}
