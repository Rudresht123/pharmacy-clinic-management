<?php

namespace App\Providers;

use App\Models\Tenant\PersonalAccessToken;
use App\Services\Permissions\Permission;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * One Permission per request.
         *
         * `scoped` rather than `singleton`: the object holds request state —
         * which branch this request is happening in, set once by
         * ResolveActingBranch after checking membership — and a singleton
         * would carry that between requests on a queue worker or Octane.
         *
         * Without a binding at all it was a fresh instance per injection, so
         * the branch the middleware resolved was set on a copy nothing else
         * ever saw, and every gate went on answering about the person's
         * default branch. The switcher appeared to do nothing.
         */
        $this->app->scoped(Permission::class);

        /*
         * The central schema lives in database/migrations/masterdb, which
         * plain `php artisan migrate` does not scan — Laravel reads only the
         * top level of database/migrations.
         *
         * Until this was registered, the only thing that created the central
         * tables was `tenants:migrate` passing --path by hand. Anything else
         * that migrates — RefreshDatabase in the test suite, a fresh clone, a
         * deploy step — silently produced a database with no organizations
         * table. Registering the paths makes `migrate` mean the same thing
         * everywhere.
         *
         * after-seed holds data migrations (default email templates) whose
         * later timestamps already order them behind the schema.
         */
        $this->loadMigrationsFrom([
            database_path('migrations/masterdb'),
            database_path('migrations/masterdb/after-seed'),
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        /*
         * Sanctum reads tokens from the tenant database, not the master one.
         *
         * A token belongs to one organization's user, so it lives inside that
         * organization's database like everything else about them. The model
         * below is pinned to the `organization` connection, which
         * ResolveTenantFromHeader has already pointed at the right database by
         * the time the guard runs.
         *
         * Registering it globally is safe only because nothing else issues
         * Sanctum tokens: the platform panel authenticates over a session, and
         * its `personal_access_tokens` table is Laravel's default migration,
         * unused. The day the platform issues a token, this line is what has to
         * change first.
         */
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         * Point reset links at the SPA rather than the Blade route.
         *
         * The default builds route('password.reset'), which still resolves to
         * a server-rendered page in routes/auth.php — so an emailed link
         * landed on the old form instead of /reset-password/{token}. The SPA
         * reads the address from the query string.
         */
        ResetPassword::createUrlUsing(
            fn (object $notifiable, string $token) => url(
                '/reset-password/'.$token.'?email='.urlencode($notifiable->getEmailForPasswordReset())
            )
        );
    }
}
