<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $table = 'email_logs';

    protected $fillable = [

        'template_key',

        'recipient',

        'subject',

        'status',

        'error_message',

        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}