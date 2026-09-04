<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A role inside the admin panel — Build Spec §18.
 *
 * These are the five panel roles, not tenant roles. `code` is what the
 * application checks; renaming `name` is safe, renaming `code` is not.
 */
class PlatformRole extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const OPS = 'ops';

    public const SUPPORT = 'support';

    public const BILLING = 'billing';

    public const CATALOG = 'catalog';

    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformUser::class,
            'platform_role_user',
            'platform_role_id',
            'platform_user_id'
        );
    }
}
