<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Record extends Model
{
    protected static function booted(): void
    {
        static::creating(function ($model) {
            $actor = self::actorId();

            if ($actor !== null && empty($model->created_by)) {
                $model->created_by = $actor;
            }

            if ($actor !== null && empty($model->updated_by)) {
                $model->updated_by = $actor;
            }
        });

        static::updating(function ($model) {
            $actor = self::actorId();

            if ($actor !== null) {
                $model->updated_by = $actor;
            }
        });
    }

    /**
     * Who is making this change.
     *
     * The platform guard, and only the platform guard. Plain Auth::id() would
     * read the default guard and quietly return null for every admin action,
     * so the guard is named.
     *
     * There used to be a `web` fallback here, written before the tenant app
     * existed and on the assumption that a tenant-side write would want it.
     * It was wrong: every model extending Record lives in the MASTER database
     * and its created_by / updated_by columns are foreign keys to
     * `platform_users`. A tenant user's id means a different person entirely,
     * and stamping it raises a foreign key violation — which is exactly how
     * this was found. Tenant models extend Model directly and are audited by
     * App\Support\History\RecordsHistory, which resolves the actor properly
     * for both sides.
     */
    protected static function actorId(): int|string|null
    {
        return Auth::guard('platform')->id();
    }

    // Scope Method

    public function scopeSearch($query, ?array $filters = [])
    {
        // Select columns if provided
        if (! empty($filters['columns'])) {
            $query->select($filters['columns']);
            unset($filters['columns']);
        }

        if (! empty($filters)) {
            foreach ($filters as $field => $value) {

                if (blank($value)) {
                    continue;
                }

                if (is_array($value) && isset($value['operator'])) {

                    $operator = $value['operator'];
                    $searchValue = $value['value'] ?? null;

                    match ($operator) {
                        'like' => $query->where($field, 'LIKE', "%{$searchValue}%"),
                        'in' => $query->whereIn($field, $searchValue),
                        '>=' => $query->where($field, '>=', $searchValue),
                        '<=' => $query->where($field, '<=', $searchValue),
                        '=' => $query->where($field, $searchValue),
                        default => null,
                    };

                } else {

                    $query->where($field, $value);
                }
            }
        }

        return $query;
    }
}
