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
     * Central records are written by platform administrators, so the platform
     * guard is asked first. Plain Auth::id() would read the default guard
     * (`web`) and quietly return null for every admin action — the stamps
     * would just stop being written, with nothing to notice.
     *
     * The `web` fallback is what a tenant-side write will use once the tenant
     * app exists.
     */
    protected static function actorId(): int|string|null
    {
        return Auth::guard('platform')->id() ?? Auth::guard('web')->id();
    }

    // Scope Method

   public function scopeSearch($query, ?array $filters = [])
{
    // Select columns if provided
    if (!empty($filters['columns'])) {
        $query->select($filters['columns']);
        unset($filters['columns']);
    }

    if(!empty($filters)){
    foreach ($filters as $field => $value) {

        if (blank($value)) {
            continue;
        }

        if (is_array($value) && isset($value['operator'])) {

            $operator = $value['operator'];
            $searchValue = $value['value'] ?? null;

            match ($operator) {
                'like' => $query->where($field, 'LIKE', "%{$searchValue}%"),
                'in'   => $query->whereIn($field, $searchValue),
                '>='   => $query->where($field, '>=', $searchValue),
                '<='   => $query->where($field, '<=', $searchValue),
                '='    => $query->where($field, $searchValue),
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
