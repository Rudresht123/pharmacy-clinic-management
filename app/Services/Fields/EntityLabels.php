<?php

namespace App\Services\Fields;

use App\Models\Tenant\EntityLabel;
use App\Support\Fields\FieldRegistry;

/**
 * Resolves what this organization calls each record, falling back to the
 * name the code ships with.
 *
 * One place, so a screen, the sidebar and an email all say the same word.
 */
class EntityLabels
{
    /**
     * @return array<string, array{singular: string, plural: string}>
     */
    public function all(): array
    {
        $overrides = EntityLabel::on('organization')->get()->keyBy('entity');

        $labels = [];

        foreach (FieldRegistry::entities() as $entity) {
            $override = $overrides->get($entity);

            $labels[$entity] = [
                'singular' => $override?->singular ?: FieldRegistry::singular($entity),
                'plural' => $override?->plural ?: FieldRegistry::label($entity),
            ];
        }

        return $labels;
    }

    /** @return array{singular: string, plural: string} */
    public function for(string $entity): array
    {
        return $this->all()[$entity] ?? [
            'singular' => FieldRegistry::singular($entity),
            'plural' => FieldRegistry::label($entity),
        ];
    }

    /**
     * @param  array{singular: string, plural: string}  $labels
     */
    public function save(string $entity, array $labels): void
    {
        EntityLabel::on('organization')->updateOrCreate(
            ['entity' => $entity],
            ['singular' => $labels['singular'], 'plural' => $labels['plural']],
        );
    }
}
