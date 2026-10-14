<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo, trial and contact requests from the marketing website.
 *
 * MASTER DATABASE: these people are not anybody's tenant yet — they are the
 * platform's own leads, read on the super admin dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_enquiries', function (Blueprint $table) {
            $table->id();

            // demo | trial | contact — see App\Models\Platform\WebsiteEnquiry.
            $table->string('type', 20);

            $table->string('name', 150);
            $table->string('organization_name', 191)->nullable();
            $table->string('email', 191);
            $table->string('phone', 30)->nullable();

            // What they said they wanted, in the website's own words.
            $table->string('interest', 191)->nullable();
            $table->text('message')->nullable();

            // new | contacted | converted | closed
            $table->string('status', 20)->default('new');

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_enquiries');
    }
};
