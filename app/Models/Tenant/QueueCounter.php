<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * The last token one doctor (or one shared department) issued, on one day, at
 * one branch.
 *
 * Written only by QueueTokenService, under this row's own lock: that lock is
 * what stops two receptionists handing out the same number, and because each
 * doctor has their own row, checking somebody in for Dr. Sharma never waits on
 * anybody checking in for Dr. Verma.
 */
class QueueCounter extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'department_id',
        'doctor_id',
        'queue_date',
        'prefix',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'department_id' => 'integer',
            'doctor_id' => 'integer',
            'queue_date' => 'date',
            'last_number' => 'integer',
        ];
    }
}
