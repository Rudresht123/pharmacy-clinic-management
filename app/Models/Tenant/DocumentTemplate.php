<?php

namespace App\Models\Tenant;

use App\Support\Deletion\HasDeletionAudit;
use App\Support\Documents\DocumentTypes;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What one kind of printed document looks like, here.
 *
 * "Here" is the whole design: a row with no `location_id` is the
 * ORGANIZATION'S DEFAULT and applies to every branch that has not customised;
 * a row with one is that branch's override and applies there alone. No third
 * table, no inheritance chain to walk — one nullable column, and a resolver
 * that asks two questions in order.
 *
 * The template is the identity. What it actually looked like on a given day is
 * a DocumentTemplateVersion, and a generated PDF points at one of those
 * forever — so editing this row never changes a document already printed.
 */
class DocumentTemplate extends Model
{
    use HasDeletionAudit;
    use RecordsHistory;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::ARCHIVED];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'document_type',
        'name',
        'description',
        'status',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class)->orderByDesc('version');
    }

    /** The one new documents are made from. Null until something is published. */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'active_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The organization's own, rather than a branch's. */
    public function isOrganizationDefault(): bool
    {
        return $this->location_id === null;
    }

    /**
     * Usable for new documents?
     *
     * Both halves, because either alone is a lie: an active template that was
     * never published has nothing to render, and a published version on an
     * archived template is one somebody deliberately took out of use.
     */
    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && $this->active_version_id !== null;
    }

    /** The next version number for this template. */
    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version') + 1;
    }

    /**
     * Templates for one branch — its own overrides AND the organization's
     * defaults, which is what that branch actually prints with.
     */
    public function scopeForBranch(Builder $query, ?int $locationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($locationId) {
            $scoped->whereNull('location_id');

            if ($locationId !== null) {
                $scoped->orWhere('location_id', $locationId);
            }
        });
    }

    /** Reads in the activity log as what it is, rather than as two ids. */
    public function historyLabel(): string
    {
        $where = $this->location?->name ?? 'organisation default';
        $type = DocumentTypes::find((string) $this->document_type)['name'] ?? $this->document_type;

        return "{$type} ({$where})";
    }
}
