<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveFieldSettingsRequest;
use App\Models\Tenant\EntityFieldSetting;
use App\Services\Fields\EntityLabels;
use App\Services\Fields\FieldSchema;
use App\Support\Fields\FieldRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Where an organization decides how a form behaves: what is mandatory, what
 * is shown, what appears as a table column, and what extra fields of its own
 * it wants to collect.
 *
 * Entity-agnostic — Locations and People are the two today, and adding a
 * third is an entry in FieldRegistry rather than a change here.
 *
 * Reads are open to any signed-in tenant user (their forms need them);
 * writes sit behind `tenant.owner` on the route.
 */
class FieldSettingController extends BaseApiController
{
    /** The list of screens that can be configured, for a settings index. */
    public function index(EntityLabels $labels): JsonResponse
    {
        $resolved = $labels->all();

        return $this->ok(array_map(
            fn (string $entity) => [
                'entity' => $entity,
                'label' => $resolved[$entity]['plural'],
                'singular' => $resolved[$entity]['singular'],
            ],
            FieldRegistry::entities()
        ));
    }

    public function show(string $entity, FieldSchema $schema, EntityLabels $labels): JsonResponse
    {
        if (! FieldRegistry::supports($entity)) {
            return $this->fail('There are no settings for that screen.', 404);
        }

        $label = $labels->for($entity);

        return $this->ok([
            'entity' => $entity,
            'label' => $label['plural'],
            'singular' => $label['singular'],
            'fields' => $schema->for($entity, FieldRegistry::for($entity)),
            // So the screen can tell "add a field" what types are on offer.
            'custom_types' => EntityFieldSetting::CUSTOM_TYPES,
        ]);
    }

    public function update(
        SaveFieldSettingsRequest $request,
        string $entity,
        FieldSchema $schema,
        EntityLabels $labels
    ): JsonResponse {
        $payload = $request->validated('fields');

        // What this organization calls the record, saved alongside its fields
        // because both are edited on the same screen.
        if ($request->filled('label.singular') && $request->filled('label.plural')) {
            $labels->save($entity, $request->validated('label'));
        }

        /*
         * Replace rather than merge: the screen always sends the whole set,
         * and a field removed from it is a field the organization deleted.
         * Wrapped so a half-applied configuration can never be left behind.
         */
        DB::connection('organization')->transaction(function () use ($entity, $payload) {
            $keys = array_column($payload, 'field_key');

            EntityFieldSetting::on('organization')
                ->where('entity', $entity)
                ->whereNotIn('field_key', $keys)
                ->delete();

            foreach ($payload as $field) {
                EntityFieldSetting::on('organization')->updateOrCreate(
                    ['entity' => $entity, 'field_key' => $field['field_key']],
                    [
                        'label' => $field['label'] ?? null,
                        'placeholder' => $field['placeholder'] ?? null,
                        'is_custom' => $field['is_custom'],
                        'data_type' => $field['data_type'] ?? null,
                        'options' => $field['options'] ?? null,
                        'is_required' => $field['is_required'],
                        'show_in_form' => $field['show_in_form'],
                        'show_in_table' => $field['show_in_table'],
                        'sort_order' => $field['sort_order'],
                    ]
                );
            }
        });

        $label = $labels->for($entity);

        return $this->ok(
            [
                'entity' => $entity,
                'label' => $label['plural'],
                'singular' => $label['singular'],
                'fields' => $schema->for($entity, FieldRegistry::for($entity)),
                'custom_types' => EntityFieldSetting::CUSTOM_TYPES,
            ],
            'Field settings saved successfully.'
        );
    }
}
