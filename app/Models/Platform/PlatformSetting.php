<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Generic platform-wide key/value store. */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
