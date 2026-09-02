<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How one organization wants a form and its table to behave.
 *
 * Two kinds of row live here:
 *
 *   - an override on a built-in field, keyed by the same `field_key` the
 *     code registry (App\Support\Fields\*) declares. The registry stays the
 *     source of truth for which built-in fields exist and which of them are
 *     locked; this table only says how the organization wants them shown.
 *   - a field the organization added itself (`is_custom`), whose values live
 *     in the owning table's `custom_fields` jsonb column.
 *
 * Per tenant database, so one organization's form never affects another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_field_settings', function (Blueprint $table) {
            $table->id();

            // Which screen this belongs to — 'location' today.
            $table->string('entity', 40);
            $table->string('field_key', 60);

            // Null means "use the registry's label".
            $table->string('label', 120)->nullable();
            $table->string('placeholder', 191)->nullable();

            $table->boolean('is_custom')->default(false);

            // Only meaningful for custom fields; built-ins take their type
            // from the registry.
            $table->string('data_type', 20)->nullable();
            $table->jsonb('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('show_in_form')->default(true);
            $table->boolean('show_in_table')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['entity', 'field_key']);
            $table->index('entity');
        });

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location'))"
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_data_type_check '.
            "CHECK (data_type IS NULL OR data_type IN ('text', 'textarea', 'number', 'date', 'boolean', 'select'))"
        );

        /*
         * A custom field is nothing without a type — the built-ins are the
         * only rows allowed to leave it null, because theirs comes from code.
         */
        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_custom_type_check '.
            'CHECK (is_custom = false OR data_type IS NOT NULL)'
        );
    }

    public function down(): void
    {
        foreach ([
            'entity_field_settings_custom_type_check',
            'entity_field_settings_data_type_check',
            'entity_field_settings_entity_check',
        ] as $constraint) {
            DB::statement("ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::dropIfExists('entity_field_settings');
    }
};
