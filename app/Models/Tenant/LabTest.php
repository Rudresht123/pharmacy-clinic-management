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
 * A test the lab can run — CBC, Lipid profile, TSH.
 *
 * Organisation-wide, one menu for every branch; a branch may charge its own
 * price (LabBranchPrice). What is read off it are its parameters, and every
 * test has at least one, so a single-value test and a panel are the same shape.
 *
 * An order copies the name and price at the moment it is placed, so editing a
 * test never rewrites what a patient was charged or what a report printed.
 */
class LabTest extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    /** Values read off it, parameter by parameter. */
    public const LAYOUT_PARAMETERS = 'parameters';

    /** A written report (histopathology, a culture), parameters optional. */
    public const LAYOUT_NARRATIVE = 'narrative';

    public const LAYOUTS = [self::LAYOUT_PARAMETERS, self::LAYOUT_NARRATIVE];

    protected $connection = 'organization';

    protected $table = 'lab_tests';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'department_id',
        'category_id',
        'sample_type_id',
        'container_id',
        'method_id',
        'sample_volume',
        'description',
        'collection_instructions',
        'processing_instructions',
        'result_layout',
        'report_template',
        'tat_hours',
        'price',
        'tax_percent',
        'hsn_code',
        'is_outsourced',
        'outsource_lab_id',
        'outsource_cost',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'category_id' => 'integer',
            'sample_type_id' => 'integer',
            'container_id' => 'integer',
            'method_id' => 'integer',
            'outsource_lab_id' => 'integer',
            'tat_hours' => 'integer',
            'price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'outsource_cost' => 'decimal:2',
            'is_outsourced' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(LabDepartment::class, 'department_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LabTestCategory::class, 'category_id')->withTrashed();
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(LabSampleType::class, 'sample_type_id')->withTrashed();
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(LabContainer::class, 'container_id')->withTrashed();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(LabMethod::class, 'method_id')->withTrashed();
    }

    public function outsourceLab(): BelongsTo
    {
        return $this->belongsTo(LabOutsourceLab::class, 'outsource_lab_id')->withTrashed();
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(LabTestParameter::class)->orderBy('position')->orderBy('id');
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(LabBranchPrice::class);
    }

    /** @param  Builder<LabTest>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** What a person reads it by — the LabMasters convention. */
    public function label(): string
    {
        return (string) $this->name;
    }

    protected function historyLabel(): ?string
    {
        return $this->code.' '.$this->name;
    }
}
