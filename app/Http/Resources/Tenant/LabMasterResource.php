<?php

namespace App\Http\Resources\Tenant;

use App\Support\Laboratory\LabMasters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A row of one laboratory list, shaped by that list's description.
 *
 * Every field the list has, plus `label` (what a person reads it by) and, for
 * each field that points at another list, that row's name beside its id —
 * `department_id` and `department_name` — so a table never has to look it up.
 *
 * @mixin Model
 */
class LabMasterResource extends JsonResource
{
    private string $master = '';

    /** One row, for the list it belongs to. */
    public static function for(string $master, Model $record): self
    {
        $resource = new self($record);
        $resource->master = $master;

        return $resource;
    }

    /**
     * Rows of one list.
     *
     * @param  iterable<Model>  $records
     * @return Collection<int, self>
     */
    public static function listOf(string $master, iterable $records): Collection
    {
        return collect($records)->map(fn (Model $record) => self::for($master, $record))->values();
    }

    public function toArray(Request $request): array
    {
        /** @var Model $record */
        $record = $this->resource;

        $row = [
            'id' => $record->getKey(),
            'label' => $record->label(),
        ];

        foreach (LabMasters::get($this->master)['fields'] as $field) {
            $name = $field['name'];
            $value = $record->getAttribute($name);

            $row[$name] = match ($field['type']) {
                'number' => $value === null ? null : (float) $value,
                'switch' => (bool) $value,
                default => $value,
            };

            if ($field['type'] === 'select') {
                $relation = LabMasters::relationFor($name);
                $related = $record->relationLoaded($relation) ? $record->getRelation($relation) : null;

                $row[preg_replace('/_id$/', '_name', $name)] = $related === null
                    ? null
                    : (method_exists($related, 'label') ? $related->label() : $related->getAttribute('name'));
            }
        }

        return [
            ...$row,
            'is_active' => (bool) $record->getAttribute('is_active'),
            'position' => (int) $record->getAttribute('position'),
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }
}
