<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            $table->string('organization_name');

            $table->string('organization_code')->unique();

            $table->foreignId('organization_type_id')->nullable();

            $table->string('subdomain')->unique();

            $table->string('database_name')->unique();

            $table->string('contact_person_name')->nullable();

            $table->string('email')->nullable();

            $table->string('phone_number')->nullable();

            $table->text('address')->nullable();

            $table->unsignedBigInteger('profile_image')->nullable();

            $table->foreign('profile_image')
                ->references('id')
                ->on('files')
                ->nullOnDelete();

            $table->string('setup_token')->nullable();

            $table->timestamp('setup_token_expires_at')
                ->nullable();

            $table->boolean('is_setup_completed')
                ->default(false);

            $table->enum('status', [
                'pending_setup',
                'active',
                'suspended',
                'expired'
            ])->default('pending_setup');

            $table->boolean('is_active')->default(1)->comment('1 = Active, 0 = Inactive');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
