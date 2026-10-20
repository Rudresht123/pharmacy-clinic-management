<?php

namespace App\Services\Exports;

use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use Illuminate\Support\Carbon;

/**
 * What one export is narrowed to — who is asking, at which branches, over
 * which dates, and whatever the export's own status and search filters say.
 *
 * `branches` is already the answer, not the request: ExportAccess has worked
 * out where this person may export and the branch they picked is inside it.
 * Null is every branch.
 */
final class ExportFilters
{
    /**
     * @param  list<int>|null  $branches
     * @param  list<int>|null  $ids  only these records — the rows ticked on screen
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly User $user,
        public readonly ?array $branches,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?string $status = null,
        public readonly ?string $search = null,
        public readonly ?int $doctor = null,
        public readonly ?array $ids = null,
    ) {}

    /** The search as a case-insensitive LIKE pattern, or null with nothing typed. */
    public function like(): ?string
    {
        $term = trim((string) $this->search);

        return $term === '' ? null : '%'.addcslashes($term, '%_\\').'%';
    }
}
