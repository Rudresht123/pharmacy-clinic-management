<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $table = 'email_templates';

    protected $fillable = [
        'template_key',
        'template_name',
        'subject',
        'body',
        'available_placeholders',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}