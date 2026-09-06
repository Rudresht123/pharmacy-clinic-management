<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A stored file belonging to one organization.
 *
 * The same table as `App\Models\File`, on the other side of the wall. That one
 * is the master database's — an organization's own logo, uploaded before the
 * tenant database exists. This one is a tenant's, and a doctor's photograph
 * has to live here or the foreign key on `doctors.photo` would be pointing at
 * a row in a different database, which is not a foreign key.
 *
 * The duplication is the price of physical tenancy and is deliberate: one
 * model with a swappable connection would be one place to forget which
 * database it is currently talking to.
 */
class File extends Model
{
    protected $connection = 'organization';

    protected $table = 'files';

    protected $fillable = [
        'file_name',
        'file_path',
        'disk',
        'mime_type',
        'file_size',
        'extension',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /** Where a browser can fetch it. */
    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk($this->disk ?: 'public')->url($this->file_path);
    }

    /**
     * Remove the bytes as well as the row.
     *
     * Called rather than hooked to a deleting event: a photograph is replaced
     * far more often than a doctor is deleted, and the replace path has to
     * clean up too — one method both use is easier to keep honest than an
     * event that only fires on one of them.
     */
    public function purge(): void
    {
        if ($this->file_path) {
            Storage::disk($this->disk ?: 'public')->delete($this->file_path);
        }

        $this->delete();
    }
}
