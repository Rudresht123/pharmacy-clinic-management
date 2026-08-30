<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'description',
        'is_enabled_globally',
    ];

    protected $casts = [
        'is_enabled_globally' => 'boolean',
    ];

    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class);
    }
}
