<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API tokens for an organization's own staff.
 *
 * In the **tenant** database, not the master one, and that is the whole point.
 * A token is an authentication artefact belonging to one organization's user;
 * putting them in a shared table would mean every organization's credentials
 * living in one place, and a bug in a token lookup becoming a cross-tenant bug
 * rather than a broken login. The database is the boundary here as everywhere
 * else, so the tokens sit inside it.
 *
 * Laravel's own `personal_access_tokens` migration stays where it is, in the
 * master database, unused. The two never meet: `ResolveTenantFromHeader` points
 * the connection at the right organization before the guard reads a token, and
 * `App\Models\Tenant\PersonalAccessToken` is pinned to that connection.
 *
 * The shape matches Sanctum's, because Sanctum's model reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();

            // The owner. A morph rather than a plain foreign key because that
            // is the column Sanctum writes; in this database it is only ever
            // a tenant user.
            $table->morphs('tokenable');

            $table->string('name');

            // The token is stored hashed. What the client holds is never in
            // this table, so a dump of it does not let anyone sign in.
            $table->string('token', 64)->unique();

            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
