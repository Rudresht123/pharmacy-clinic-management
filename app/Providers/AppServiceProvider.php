<?php

namespace App\Providers;

use App\Models\Tenant\PersonalAccessToken;
use App\Models\Tenant\User as TenantUser;
use App\Observers\TenantUserDevicesObserver;
use App\Services\Email\EmailManager;
use App\Services\Permissions\Permission;
use App\Services\Platform\ProvisioningProgress;
use App\Services\Portal\Otp\LogOtpSender;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Push\FcmPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Push\PushSender;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Event;
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

        // How a patient's sign-in code reaches their phone. One driver today;
        // an SMS provider is a new `match` arm, not a change to the sign-in.
        /*
         * WhatsApp, one instance per request.
         *
         * `scoped` rather than `singleton` for the same reason the branch
         * resolver is: it holds which organization it is acting for, and a
         * singleton would carry one request's clinic into the next. The
         * manager itself returns a clone from forOrganization(), so two
         * callers in one request cannot change each other's target either.
         */
        $this->app->scoped(WhatsAppManager::class);
        $this->app->scoped(EmailManager::class);

        /*
         * One per worker process: it holds the provisioning step whose
         * migrations are being counted, between the migrator's events. A
         * worker runs one job at a time, and the job clears it when done.
         */
        $this->app->singleton(ProvisioningProgress::class);

        $this->app->bind(OtpSender::class, fn () => match (config('services.otp.driver')) {
            default => new LogOtpSender,
        });

        /*
         * How a push reaches a phone. `log` until a Firebase project is
         * configured — see config/push.php. Resolved per use, so changing
         * PUSH_DRIVER needs no restart of anything but the worker.
         */
        $this->app->bind(PushSender::class, fn () => match (config('push.driver')) {
            'fcm' => FcmPushSender::fromConfig(),
            default => new LogPushSender,
        });

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
         * Somebody deactivated or removed stops receiving this organization's
         * pushes at once, however it happened — the People screen, a seeder,
         * a console command.
         */
        TenantUser::observe(TenantUserDevicesObserver::class);

        /*
         * A new tenant's migrations, counted onto the provisioning step the
         * administrator is watching. Nothing is written unless provisioning
         * is tracking a step — tenants:migrate and the tests fire these too.
         */
        Event::listen(MigrationStarted::class, fn () => $this->app->make(ProvisioningProgress::class)->started());
        Event::listen(MigrationEnded::class, fn () => $this->app->make(ProvisioningProgress::class)->ended());

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

        /*
        | Scribe (dev-only, so this class only exists when scribe:generate can
        | run) instantiates FormRequests with `new $class` to read their
        | rules(), which fails on every FormRequest here that takes a
        | constructor dependency — e.g. the two LoginRequests take a tenant/
        | connection service. Routing it through the container instead makes
        | those resolve the same way a real request would.
        */
        if (class_exists(\Knuckles\Scribe\Tools\Globals::class)) {
            // `build()`, not `make()`: make() also fires the container's
            // afterResolving(ValidatesWhenResolved::class) hook, which runs
            // the FormRequest's real validation against an empty request and
            // throws. build() only resolves constructor dependencies.
            \Knuckles\Scribe\Tools\Globals::$__instantiateFormRequestUsing =
                fn (string $class) => $this->app->build($class);
        }
    }
}
