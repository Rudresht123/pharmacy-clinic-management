<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // Original file name
            $table->string('file_name');

            // Relative storage path
            $table->string('file_path');

            // local, public, s3, azure, etc.
            $table->string('disk')->default('public');

            // image/png, application/pdf, etc.
            $table->string('mime_type')->nullable();

            // File size in bytes
            $table->unsignedBigInteger('file_size')->nullable();

            // Optional metadata
            $table->string('extension', 20)->nullable();

            $table->timestamps();

            $table->index('file_path');
            $table->index('disk');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};