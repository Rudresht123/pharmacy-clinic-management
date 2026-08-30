<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The routing table: which physical database a tenant lives in, its
 * credentials, and its technical (not commercial) state.
 *
 * `organizations.status` is the commercial lifecycle; `provision_status` and
 * `status` here are the technical state of the database itself — a
 * suspended organization can still have a healthy database, and a failed
 * provision is not a churned customer. Conflating the two makes both
 * questions unanswerable from one column.
 */
class TenantDatabase extends Model
{
    protected $fillable = [
        'organization_id',
        'db_cluster_id',
        'db_name',
        'db_user',
        'db_password_encrypted',
        'provision_status',
        'status',
        'size_bytes',
        'last_backup_at',
        'last_backup_status',
    ];

    protected $casts = [
        'db_password_encrypted' => 'encrypted',
        'size_bytes' => 'integer',
        'last_backup_at' => 'datetime',
    ];

    protected $hidden = [
        'db_password_encrypted',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dbCluster(): BelongsTo
    {
        return $this->belongsTo(DbCluster::class);
    }

    /**
     * The technical (provision_status, status) pair a commercial
     * `organizations.status` value implies, used to backfill this table for
     * organizations that already exist and to give the eventual
     * provisioning rewrite one place to keep the two in sync.
     *
     * @return array{provision_status: string, status: ?string}
     */
    public static function mapOrganizationStatus(string $organizationStatus): array
    {
        return match ($organizationStatus) {
            Organization::ACTIVE, Organization::SUSPENDED, Organization::CANCELLED => [
                'provision_status' => 'provisioned',
                'status' => 'healthy',
            ],
            Organization::FAILED => [
                'provision_status' => 'failed',
                'status' => null,
            ],
            Organization::PROVISIONING => [
                'provision_status' => 'provisioning',
                'status' => null,
            ],
            default => [ // PENDING, or anything unrecognized
                'provision_status' => 'pending',
                'status' => null,
            ],
        };
    }
}
