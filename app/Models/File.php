<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class File extends Model
{
    protected $table = 'files';

    protected $fillable = ['file_name', 'file_path', 'disk', 'mime_type', 'file_size', 'extension'];

    protected $appends = ['url'];

    /**
     * Get file URL.
     */
    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        // Same origin the browser is on, not APP_URL's one host and port. See
        // the note on App\Models\Tenant\File::getUrlAttribute.
        if ($this->disk === 'public' && ! app()->runningInConsole()) {
            return request()->getSchemeAndHttpHost().'/storage/'.ltrim($this->file_path, '/');
        }

        return Storage::disk($this->disk)->url($this->file_path);
    }

    /**
     * Get full storage path.
     */
    public function getFullPathAttribute(): string
    {
        return Storage::disk($this->disk)->path($this->file_path);
    }
}
