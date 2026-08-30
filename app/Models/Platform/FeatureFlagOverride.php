<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A per-organization on/off override of a {@see FeatureFlag}'s global default. */
class FeatureFlagOverride extends Model
{
    protected $fillable = [
        'feature_flag_id',
        'organization_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function featureFlag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
