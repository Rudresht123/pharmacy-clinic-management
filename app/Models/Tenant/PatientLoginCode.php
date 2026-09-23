<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One outstanding one-time code for a phone number.
 *
 * Only the hash is stored. See PatientOtp for the rules — expiry, attempts,
 * and the resend cool-down — which live in one place rather than on the model.
 */
class PatientLoginCode extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'phone',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'requested_ip',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** Not used, not expired. Attempts are checked by PatientOtp, not here. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
