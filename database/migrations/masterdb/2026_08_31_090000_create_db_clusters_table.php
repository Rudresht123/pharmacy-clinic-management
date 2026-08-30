<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical PostgreSQL servers a tenant database can live on (Central/
 * Platform DB reference, table guide). One row for years — but the column
 * that makes sharding a config change later instead of a rewrite.
 *
 * Seeded with the one server every tenant database already lives on, read
 * from the existing `pgsql` connection config rather than hardcoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('host', 191);
            $table->unsignedSmallInteger('port');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::table('db_clusters')->insert([
            'name' => 'primary',
            'host' => config('database.connections.pgsql.host'),
            'port' => (int) config('database.connections.pgsql.port'),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('db_clusters');
    }
};
