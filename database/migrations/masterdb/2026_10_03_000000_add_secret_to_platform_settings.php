<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for a platform-wide setting to keep a credential.
 *
 * A SEPARATE COLUMN rather than encrypting `value`. Two reasons: `value` is a
 * json column, and ciphertext is not json, so the cast and the column type
 * would disagree; and most settings are not secret, so encrypting all of them
 * would make the one table nobody can read while debugging.
 *
 * What goes here is the sending account a provider is reached with — the
 * WhatsApp vendor UID and token are the platform's, not a clinic's: there is
 * one commercial relationship with the provider and one number patients see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // Text, not json: the cast writes a ciphertext string. Long,
            // because encryption inflates and a token is already 64 characters.
            $table->text('secret')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('secret');
        });
    }
};
