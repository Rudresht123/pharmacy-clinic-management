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
 * A medicine in the organization's catalogue.
 *
 * What a medicine is, not how much of it there is: stock lives on batches
 * (Phase 3) and prescriptions copy what they need into snapshots (Phase 4).
 *
 * Removed with a reason, restored with a reason, and never erased — a
 * prescribed or stocked medicine is evidence other records depend on.
 */
class Medicine extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    /*
     * The fixed lists, mirrored by CHECK constraints on the table. The
     * medicine form offers them with `fixed_options`, so an organization can
     * relabel the field but never put a value here the database refuses.
     */
    public const DOSAGE_FORMS = [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'ointment', 'cream', 'drops',
        'inhaler', 'powder', 'gel', 'lotion', 'patch', 'suppository', 'other',
    ];

    public const ROUTES = [
        'oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'ophthalmic', 'otic', 'nasal',
        'rectal', 'vaginal', 'sublingual', 'other',
    ];

    public const BASE_UNITS = ['tablet', 'capsule', 'bottle', 'vial', 'ampoule', 'tube', 'sachet', 'unit'];

    public const SCHEDULES = ['OTC', 'G', 'H', 'H1', 'X'];

    /** The columns that together make a medicine distinct. */
    public const IDENTITY = ['generic_name', 'brand_name', 'strength', 'dosage_form', 'manufacturer'];

    protected $connection = 'organization';

    protected $fillable = [
        'medicine_code',
        'generic_name',
        'brand_name',
        'strength',
        'dosage_form',
        'route',
        'base_unit',
        'pack_size',
        'manufacturer',
        'category',
        'schedule',
        'prescription_required',
        'description',
        'is_active',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'pack_size' => 'integer',
            'prescription_required' => 'boolean',
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /** Who removed it, while it is removed. */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** @param  Builder<Medicine>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * How the medicine reads on a screen: "Dolo 650 (Paracetamol) 650 mg tablet".
     *
     * The brand first when there is one, because that is what is on the
     * strip; the generic beside it, because that is what the doctor means.
     */
    public function displayName(): string
    {
        $name = $this->brand_name
            ? "{$this->brand_name} ({$this->generic_name})"
            : (string) $this->generic_name;

        return trim(implode(' ', array_filter([$name, $this->strength, $this->dosage_form])));
    }

    protected function historyLabel(): ?string
    {
        return mb_substr($this->displayName(), 0, 191);
    }
}
