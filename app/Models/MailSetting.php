<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailSetting extends Model
{
    protected $table = 'mail_settings';

    protected $fillable = [

        'mail_mailer',

        'mail_host',
        'mail_port',

        'mail_username',
        'mail_password',

        'mail_encryption',

        'mail_from_address',
        'mail_from_name',

        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}