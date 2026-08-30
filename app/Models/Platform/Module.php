<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Catalogue of sellable/licensable HMS modules. */
class Module extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
