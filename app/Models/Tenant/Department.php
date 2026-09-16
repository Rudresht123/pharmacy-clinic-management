<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A department, or a sub-department of one.
 *
 * Two levels: a row with no parent is a department, a row with a parent is a
 * sub-department of it, and a sub-department has none of its own
 * (DepartmentService keeps it that way). Organisation-wide — a doctor works
 * at whichever branches they are posted to.
 *
 * Deactivated when it stops being offered; removed, with a reason, only once
 * nothing is in it.
 */
class Department extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    protected $connection = 'organization';

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The department above, for a sub-department. With history, so a removed one still names it. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /** The people who sign in and work here — receptionists, nurses, technicians. */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class, 'department_id');
    }

    /** @param  Builder<Department>  $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /** The department a sub-department belongs to — itself, for a department. */
    public function topLevelName(): string
    {
        return $this->parent_id ? (string) $this->parent?->name : $this->name;
    }

    /** "Cardiology › Interventional Cardiology" */
    public function path(): string
    {
        return $this->parent_id ? "{$this->parent?->name} › {$this->name}" : $this->name;
    }

    protected function historyLabel(): ?string
    {
        return mb_substr($this->path(), 0, 191);
    }
}
