<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Export Center file, made on the queue (App\Jobs\RunExport).
 *
 * queued → running → completed, or failed / cancelled on the way; a completed
 * file is deleted a day later and the run reads as expired. The row itself is
 * kept: who exported what, and when.
 *
 * @property int $id
 * @property int $user_id
 * @property string $dataset
 * @property string $format
 * @property string $title
 * @property string $noun
 * @property array<string, mixed> $filters
 * @property string $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property string|null $file_name
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $dismissed_at
 * @property Carbon $created_at
 */
class ExportRun extends Model
{
    protected $connection = 'organization';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** A completed run whose file has been deleted. */
    public const EXPIRED = 'expired';

    public const STATUSES = [self::QUEUED, self::RUNNING, self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED];

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /** How long a finished file is kept — it holds patient records. */
    public const KEEP_HOURS = 24;

    /** How many files one person may have in the making at once. */
    public const MAX_ACTIVE = 3;

    /** The private disk the files are written to. */
    public const DISK = 'local';

    protected $fillable = [
        'user_id',
        'dataset',
        'format',
        'title',
        'noun',
        'filters',
        'status',
        'total_rows',
        'processed_rows',
        'file_name',
        'file_path',
        'file_size',
        'error',
        'started_at',
        'finished_at',
        'expires_at',
        'downloaded_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Completed, and its file not yet deleted. */
    public function isReady(): bool
    {
        return $this->status === self::COMPLETED
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The status the person is shown: a completed file past its day reads as expired before the sweep deletes it. */
    public function shownStatus(): string
    {
        return $this->status === self::COMPLETED && ! $this->isReady() ? self::EXPIRED : $this->status;
    }

    /**
     * Seconds left, from the pace so far — null until there is a pace to go
     * by, and once every row is read (the file is being closed).
     */
    public function secondsLeft(): ?int
    {
        if ($this->status !== self::RUNNING || $this->started_at === null || $this->processed_rows <= 0) {
            return null;
        }

        $left = $this->total_rows - $this->processed_rows;

        if ($left <= 0) {
            return null;
        }

        $elapsed = max(1, $this->started_at->diffInSeconds(Carbon::now(), true));

        return (int) ceil($left * $elapsed / $this->processed_rows);
    }
}
