<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff belong to departments too — a receptionist in General Medicine, a
 * technician in Cardiac Diagnostics — not only doctors. Optional: somebody
 * who works across the clinic belongs to none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('location_id')
                ->constrained('departments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
