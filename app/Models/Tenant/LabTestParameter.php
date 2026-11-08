<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One value read off a test — Haemoglobin on a CBC, or the whole of a TSH.
 *
 * How it is answered is its `result_type`: a number (with a unit and its
 * decimal places), free text, one of a list (each answer saying whether it is
 * abnormal), or positive/negative. What normal looks like is its reference
 * ranges, which only `laboratory.ranges_manage` may write.
 */
class LabTestParameter extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const NUMERIC = 'numeric';

    public const TEXT = 'text';

    public const CHOICE = 'choice';

    public const POSITIVE_NEGATIVE = 'positive_negative';

    /** Every value the database CHECK permits. */
    public const RESULT_TYPES = [self::NUMERIC, self::TEXT, self::CHOICE, self::POSITIVE_NEGATIVE];

    protected $connection = 'organization';

    protected $table = 'lab_test_parameters';

    protected $fillable = [
        'lab_test_id',
        'name',
        'code',
        'section',
        'result_type',
        'unit_id',
        'decimals',
        'choices',
        'normal_value',
        'method_id',
        'is_required',
        'is_printed',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'lab_test_id' => 'integer',
            'unit_id' => 'integer',
            'method_id' => 'integer',
            'decimals' => 'integer',
            'choices' => 'array',
            'is_required' => 'boolean',
            'is_printed' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id')->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(LabUnit::class, 'unit_id')->withTrashed();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(LabMethod::class, 'method_id')->withTrashed();
    }

    public function ranges(): HasMany
    {
        return $this->hasMany(LabReferenceRange::class)->orderBy('position')->orderBy('id');
    }

    public function isNumeric(): bool
    {
        return $this->result_type === self::NUMERIC;
    }

    protected function historyLabel(): ?string
    {
        return $this->name;
    }
}
