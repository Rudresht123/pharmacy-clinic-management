<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * One organization's word for a record — "Customer", "Patient", "Client".
 */
class EntityLabel extends Model
{
    protected $connection = 'organization';

    protected $table = 'entity_labels';

    protected $fillable = ['entity', 'singular', 'plural'];
}
