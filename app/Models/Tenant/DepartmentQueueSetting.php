<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A department that hands out one run of numbers between its doctors.
 *
 * Optional. With no row here every doctor keeps their own sequence; with one,
 * all the department's doctors at that branch share its prefix and its counter,
 * for a clinic whose department has a single token machine at its door.
 */
class DepartmentQueueSetting extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'department_id',
        'token_prefix',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'department_id' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    protected function historyLabel(): ?string
    {
        return 'Queue settings · department #'.$this->department_id;
    }
}
