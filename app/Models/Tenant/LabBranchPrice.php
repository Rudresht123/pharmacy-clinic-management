<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's own price for a test, where it differs from the organisation's.
 *
 * Prices are the one thing a branch may set for itself (with numbering). No row
 * means the branch charges the test's own price; a null tax takes the test's.
 */
class LabBranchPrice extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $table = 'lab_branch_prices';

    protected $fillable = ['location_id', 'lab_test_id', 'price', 'tax_percent'];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'lab_test_id' => 'integer',
            'price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id')->withTrashed();
    }

    protected function historyLabel(): ?string
    {
        return 'Branch price';
    }
}
