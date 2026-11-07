<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How this organisation's laboratory works: one row, the database's own rule.
 *
 * Read by every lab screen, changed under `laboratory.settings_manage`. The
 * values decide the workflow rather than a single screen: how many people a
 * result passes before it is final, whether a sample waits for its bill,
 * whether a critical value can be signed before anybody was told.
 */
class LabSetting extends Model
{
    use RecordsHistory;

    /** Enter, then approve. */
    public const ONE_LEVEL = 1;

    /** Enter, then a technical verification, then approve. */
    public const TWO_LEVELS = 2;

    public const APPROVAL_LEVELS = [self::ONE_LEVEL, self::TWO_LEVELS];

    public const LABEL_50X25 = '50x25';

    public const LABEL_38X25 = '38x25';

    public const LABEL_A4 = 'a4_sheet';

    /** The label stocks a collection counter prints on, in millimetres. */
    public const LABEL_SIZES = [self::LABEL_50X25, self::LABEL_38X25, self::LABEL_A4];

    protected $connection = 'organization';

    protected $table = 'lab_settings';

    protected $fillable = [
        'approval_levels',
        'allow_self_review',
        'collect_requires_payment',
        'release_partial_reports',
        'critical_call_required',
        'urgent_tat_hours',
        'stat_tat_minutes',
        'label_size',
    ];

    protected function casts(): array
    {
        return [
            'approval_levels' => 'integer',
            'allow_self_review' => 'boolean',
            'collect_requires_payment' => 'boolean',
            'release_partial_reports' => 'boolean',
            'critical_call_required' => 'boolean',
            'urgent_tat_hours' => 'integer',
            'stat_tat_minutes' => 'integer',
        ];
    }

    /**
     * The organisation's settings.
     *
     * `firstOrCreate` for the one case the migration cannot cover: a tenant
     * database restored from before this table existed. The defaults are the
     * table's own.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return 'Laboratory settings';
    }
}
