<?php

namespace App\Models\Tenant;

use App\Support\Deletion\HasDeletionAudit;
use App\Support\Documents\DocumentCategories;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One file kept against a patient.
 *
 * What the file IS lives here; the bytes live in `files`. The split is
 * deliberate — a document can be removed from a patient's record while the
 * stored object is purged separately, and `files` stays the one place that
 * knows about disks.
 */
class PatientDocument extends Model
{
    use HasDeletionAudit;
    use RecordsHistory;
    use SoftDeletes;

    protected $connection = 'organization';

    /** Scanned or photographed by somebody. */
    public const UPLOADED = 'uploaded';

    /** Printed by this software from a template. */
    public const GENERATED = 'generated';

    protected $fillable = [
        'customer_id',
        'appointment_id',
        'file_id',
        'location_id',
        'category',
        'source',
        'document_template_id',
        'document_template_version_id',
        'title',
        'document_number',
        'notes',
        'uploaded_by',
        // Set only by the automation — see DocumentService::generate().
        'idempotency_key',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The template this was printed from, and the exact version of it.
     *
     * The VERSION is the one that matters: it is what makes a prescription
     * printed in September still readable as the document it was, rather than
     * as whatever the branch's template says today.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'document_template_version_id');
    }

    public function wasGenerated(): bool
    {
        return $this->source === self::GENERATED;
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** Derived, never stored — see the migration. */
    public function isClinical(): bool
    {
        return DocumentCategories::isClinical((string) $this->category);
    }

    /**
     * Narrows a query to what a reader may actually open.
     *
     * On the QUERY rather than on the results: a clinical document must not be
     * counted, paginated, or have its title returned to somebody who may not
     * read it. A filter applied afterwards leaks all three.
     */
    public function scopeReadableBy(Builder $query, bool $clinical): Builder
    {
        if ($clinical) {
            return $query;
        }

        return $query->whereIn('category', DocumentCategories::readableBy(false));
    }
}
