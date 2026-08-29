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
        if (!$this->file_path) {
            return null;
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
