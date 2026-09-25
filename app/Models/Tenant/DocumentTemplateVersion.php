<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * What a template looked like, at a point in time.
 *
 * IMMUTABLE ONCE PUBLISHED. A generated PDF points at one of these rows, so
 * this is the thing that makes a prescription printed in September still carry
 * September's logo and September's footer when it is read back next year.
 * Editing a published version would rewrite history rather than record it —
 * a new version is written instead, which is what `version` counts.
 *
 * The WHOLE config is stored, not a diff. A document read back in five years
 * has to be reconstructable from one row, without replaying a chain of
 * changes, and a diff would make that reconstruction a piece of code that must
 * still be correct in five years.
 */
class DocumentTemplateVersion extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'document_template_id',
        'version',
        'config',
        'locked_fields',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'config' => 'array',
            'locked_fields' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * One setting out of the config, by dotted path.
     *
     * `header.logo_position`, `layout.paper`. Arr::get rather than a column
     * per setting, because what a template can carry grows with the renderer
     * and a migration per new field is how a design like this ossifies.
     */
    public function setting(string $path, mixed $fallback = null): mixed
    {
        return Arr::get($this->config ?? [], $path, $fallback);
    }

    /**
     * Whether a branch may change this path.
     *
     * Asked of the ORGANIZATION default's version, never of the branch's own —
     * a branch that could answer this about itself could unlock anything.
     *
     * A locked parent locks everything under it: locking `header` locks
     * `header.legal_name` without having to list every field beneath it, which
     * is the behaviour somebody expects from a lock on a section.
     */
    public function locks(string $path): bool
    {
        foreach ($this->locked_fields ?? [] as $locked) {
            if ($path === $locked || str_starts_with($path, $locked.'.')) {
                return true;
            }
        }

        return false;
    }

    public function historyLabel(): string
    {
        return "v{$this->version}";
    }
}
