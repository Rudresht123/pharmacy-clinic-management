<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The central schema lives in database/migrations/masterdb, which
         * plain `php artisan migrate` does not scan — Laravel reads only the
         * top level of database/migrations.
         *
         * Until this was registered, the only thing that created the central
         * tables was `system:migrate` passing --path by hand. Anything else
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
