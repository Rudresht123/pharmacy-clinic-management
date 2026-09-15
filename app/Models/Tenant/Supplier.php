<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who goods come from. Shared by every branch of the organization.
 */
class Supplier extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    protected $connection = 'organization';

    protected $fillable = [
        'name',
        'code',
        'gstin',
        'drug_license_no',
        'drug_license_expiry_date',
        'contact_person',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'drug_license_expiry_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** Who removed it, while it is removed. */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** @param  Builder<Supplier>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
