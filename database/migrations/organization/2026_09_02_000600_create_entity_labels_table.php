<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one organization calls a record.
 *
 * The same person is a "customer" at a pharmacy counter and a "patient" in a
 * clinic — one record either way, which is the whole point of the customer
 * table, so this changes the wording rather than the schema. An organization
 * doing both keeps whichever word its staff actually use.
 *
 * No CHECK on `entity`: unlike entity_field_settings, a label for an entity
 * that later disappears is harmless, and FieldRegistry is the authority on
 * which ones are real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_labels', function (Blueprint $table) {
            $table->id();

            $table->string('entity', 40)->unique();
            $table->string('singular', 60);
            $table->string('plural', 60);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_labels');
    }
};
