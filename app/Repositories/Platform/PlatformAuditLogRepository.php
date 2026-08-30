<?php

namespace App\Repositories\Platform;

use App\Models\Platform\PlatformAuditLog;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\PlatformAuditLogRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformAuditLogRepository extends BaseRepository implements PlatformAuditLogRepositoryInterface
{
    public function __construct(PlatformAuditLog $model)
    {
        parent::__construct($model);
    }

    /**
     * The table is append-only at the database level (a trigger raises on
     * UPDATE/DELETE); failing fast here gives a caller a clear application
     * error instead of an opaque Postgres exception surfacing from it.
     */
    public function update(Model $model, array $attributes): Model
    {
        throw new LogicException('platform_audit_logs is append-only.');
    }

    public function delete(Model $model): bool
    {
        throw new LogicException('platform_audit_logs is append-only.');
    }
}
