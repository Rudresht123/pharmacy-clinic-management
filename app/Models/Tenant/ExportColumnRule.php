<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The columns one role may not take away in one export.
 *
 * Hidden rather than shown: no row means every column, and a column added to
 * an export later reaches the roles nobody narrowed. A rule hiding every
 * column takes that export away from the role altogether.
 */
class ExportColumnRule extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'role_id',
        'dataset',
        'hidden_columns',
    ];

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'hidden_columns' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
