<?php

namespace App\Services\Platform;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\OrganizationProfile;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\WebsiteEnquiry;
use App\Services\Tenant\DashboardWindow;
use Illuminate\Support\Str;

/**
 * The super admin's first screen — the platform as a whole.
 *
 * Real figures only, never sample ones. Subscriptions, plans and revenue
 * have no table yet (`organizations.plan_id` points at nothing), and clinic,
 * user, doctor and patient counts live inside each tenant's own database,
 * which this never reaches into: `organization_stats` is where they will
 * arrive once the tenant outbox relay writes them.
 *
 * Every panel is always in the answer. One with nothing behind it yet is
 * null — or a figure whose value is null — and the screen draws it as an
 * empty state rather than leaving a gap, so the layout is the same today as
 * the day the data arrives.
 */
class PlatformDashboard
{
    /** How many rows each list panel shows. */
    private const LIST_SIZE = 5;

    /** Activity sits beside the map, so it shows enough to match its height. */
    private const ACTIVITY_SIZE = 7;

    private const ENQUIRY_ROWS = 6;

    /** A module ending within this many days is worth chasing now — as ModuleController counts it. */
    private const EXPIRING_SOON_DAYS = 30;

    /** @return array<string, mixed> */
    public function for(DashboardWindow $window): array
    {
        return [
            'window' => $window->toArray(),
            'kpis' => $this->kpis($window),

            // No subscription or billing tables on the platform yet.
            'revenue_trend' => null,
            'by_plan' => null,
            'top_organizations' => null,
            'subscription_revenue' => null,

            'by_status' => $this->byStatus(),
            'new_organizations' => $this->newOrganizations(),
            'recent_signups' => $this->recentSignups(),
            'module_usage' => $this->moduleUsage(),
            'locations' => $this->locations(),
            'activity' => $this->activity(),
            'enquiries' => $this->enquiries($window),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Website enquiries
    |--------------------------------------------------------------------------
    */

    /**
     * Demo, trial and contact requests from the website: how many came in
     * this period, by kind, how many nobody has answered, and the latest.
     *
     * @return array<string, mixed>
     */
    private function enquiries(DashboardWindow $window): array
    {
        $previous = $window->previous();

        $byType = WebsiteEnquiry::query()
            ->whereBetween('created_at', [$window->from, $window->to])
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $total = (int) $byType->sum();
        $before = WebsiteEnquiry::whereBetween('created_at', [$previous->from, $previous->to])->count();

        return [
            'total' => $total,
            'change' => $this->change($total, $before),
            'demo' => (int) ($byType[WebsiteEnquiry::DEMO] ?? 0),
            'trial' => (int) ($byType[WebsiteEnquiry::TRIAL] ?? 0),
            'contact' => (int) ($byType[WebsiteEnquiry::CONTACT] ?? 0),
            // Any date: an unanswered lead from last month is still unanswered.
            'awaiting_reply' => WebsiteEnquiry::where('status', WebsiteEnquiry::NEW)->count(),
            'rows' => WebsiteEnquiry::query()
                ->latest()
                ->latest('id')
                ->limit(self::ENQUIRY_ROWS)
                ->get()
                ->map(fn (WebsiteEnquiry $enquiry) => [
                    'id' => $enquiry->id,
                    'type' => $enquiry->type,
                    'name' => $enquiry->name,
                    'organization_name' => $enquiry->organization_name,
                    'email' => $enquiry->email,
                    'phone' => $enquiry->phone,
                    'interest' => $enquiry->interest,
                    'status' => $enquiry->status,
                    'created_at' => $enquiry->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Figures
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    private function kpis(DashboardWindow $window): array
    {
        $previous = $window->previous();

        // Totals as they stood at the end of each window, so the change is
        // growth of the platform rather than a count of signups.
        $total = Organization::where('created_at', '<=', $window->to)->count();
        $totalBefore = Organization::where('created_at', '<=', $previous->to)->count();

        $joined = Organization::whereBetween('created_at', [$window->from, $window->to])->count();
        $joinedBefore = Organization::whereBetween('created_at', [$previous->from, $previous->to])->count();

        $active = Organization::where('status', Organization::ACTIVE)->count();
        $all = Organization::count();

        // Invited, but the owner has not finished the setup link yet.
        $awaiting = Organization::whereNull('setup_completed_at')
            ->whereNotIn('status', [Organization::CANCELLED, Organization::FAILED])
            ->count();

        $suspended = Organization::where('status', Organization::SUSPENDED)->count();
        $cancelled = Organization::where('status', Organization::CANCELLED)->count();

        /*
         * Modules sold and in force. Liveness is OrganizationModule::isLive(),
         * in PHP, for the reason moduleUsage() gives.
         */
        $live = OrganizationModule::query()
            ->whereHas('organization')
            ->get()
            ->filter(fn (OrganizationModule $binding) => $binding->isLive());

        $expiring = $live
            ->filter(fn (OrganizationModule $binding) => $binding->expires_at !== null
                && $binding->expires_at->isBefore(now()->addDays(self::EXPIRING_SOON_DAYS)))
            ->count();

        /*
         * Every figure here is the platform's own. Clinic, user, doctor and
         * patient counts are deliberately not on this row: they live in the
         * organizations' databases, which the platform does not read.
         */
        return [
            [
                'key' => 'organizations',
                'label' => 'Total Organizations',
                'value' => $total,
                'change' => $this->change($total, $totalBefore),
            ],
            [
                'key' => 'active',
                'label' => 'Active Organizations',
                'value' => $active,
                'change' => null,
                'hint' => $all > 0 ? round($active / $all * 100).'% of all organizations' : null,
            ],
            [
                'key' => 'joined',
                'label' => 'New Organizations',
                'value' => $joined,
                'change' => $this->change($joined, $joinedBefore),
            ],
            [
                'key' => 'awaiting',
                'label' => 'Awaiting Setup',
                'value' => $awaiting,
                'change' => null,
                'hint' => 'Owner has not signed up yet',
            ],
            [
                'key' => 'suspended',
                'label' => 'Suspended',
                'value' => $suspended,
                'change' => null,
                'hint' => $cancelled > 0 ? "{$cancelled} cancelled" : 'Logins blocked, data kept',
            ],
            [
                'key' => 'modules',
                'label' => 'Live Module Subscriptions',
                'value' => $live->count(),
                'change' => null,
                'hint' => $expiring > 0
                    ? "{$expiring} expiring in ".self::EXPIRING_SOON_DAYS.' days'
                    : 'None expiring soon',
            ],
        ];
    }

    /** Whole percent, or null when the period before had nothing to compare with. */
    private function change(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Organizations by status
    |--------------------------------------------------------------------------
    */

    /** @return list<array{key: string, label: string, total: int}> */
    private function byStatus(): array
    {
        $counts = Organization::query()
            ->get(['status', 'trial_ends_at'])
            ->countBy(fn (Organization $organization) => $this->bucket($organization));

        $buckets = [
            'active' => 'Active',
            'trial' => 'Trial',
            'setting_up' => 'Setting Up',
            'suspended' => 'Suspended',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
        ];

        /*
         * The four a platform is always asked about stay in the list at zero
         * — "0 suspended" is an answer. Setting up and failed are transient,
         * and shown only while something is in them.
         */
        $always = ['active', 'trial', 'suspended', 'cancelled'];

        $rows = [];

        foreach ($buckets as $key => $label) {
            $total = (int) ($counts[$key] ?? 0);

            if ($total > 0 || in_array($key, $always, true)) {
                $rows[] = ['key' => $key, 'label' => $label, 'total' => $total];
            }
        }

        return $rows;
    }

    /**
     * Where an organization stands, in the screen's words.
     *
     * Trial is not a status of its own: it is an active organization whose
     * trial has not ended yet. Pending and provisioning are both "being set
     * up" to anybody not debugging the provisioning job.
     */
    private function bucket(Organization $organization): string
    {
        return match ($organization->status) {
            Organization::ACTIVE => $organization->trial_ends_at?->isFuture() ? 'trial' : 'active',
            Organization::PENDING, Organization::PROVISIONING => 'setting_up',
            default => $organization->status,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Lists
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    private function newOrganizations(): array
    {
        return Organization::query()
            ->with('organizationType')
            ->latest()
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (Organization $organization) => [
                ...$this->row($organization),
                'joined_on' => $organization->created_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * Owners who finished their invitation — the moment an organization
     * actually signed up, as against the moment it was created for them.
     *
     * @return list<array<string, mixed>>
     */
    private function recentSignups(): array
    {
        return Organization::query()
            ->with('organizationType')
            ->whereNotNull('setup_completed_at')
            ->latest('setup_completed_at')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (Organization $organization) => [
                ...$this->row($organization),
                'signed_up_at' => $organization->setup_completed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * What every list row shares. Plan and clinics are null rather than
     * absent: there is no plan table and no clinic count on this side, and
     * the screen drops a column only every row leaves empty.
     *
     * @return array<string, mixed>
     */
    private function row(Organization $organization): array
    {
        return [
            'uuid' => $organization->uuid,
            'name' => $organization->organization_name,
            'type' => $organization->organizationType?->name,
            'plan' => null,
            'clinics' => null,
            'status' => $this->bucket($organization),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Module usage
    |--------------------------------------------------------------------------
    */

    /**
     * The share of working organizations holding each module.
     *
     * Core modules are left out — every organization has them, so they would
     * all read 100% and say nothing. Liveness is OrganizationModule::isLive(),
     * decided in PHP for the same reason ModuleController does: enabled,
     * started and unexpired is one opinion, and a SQL copy of it would be a
     * second one that drifts.
     *
     * @return array{organizations: int, rows: list<array{key: string, label: string, organizations: int, share: int}>}|null
     */
    private function moduleUsage(): ?array
    {
        $working = Organization::where('status', Organization::ACTIVE)->pluck('id');

        if ($working->isEmpty()) {
            return null;
        }

        $holders = OrganizationModule::query()
            ->whereIn('organization_id', $working)
            ->get()
            ->filter(fn (OrganizationModule $binding) => $binding->isLive())
            ->groupBy('module_id')
            ->map(fn ($bindings) => $bindings->pluck('organization_id')->unique()->count());

        $rows = Module::available()
            ->bindable()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'label' => $module->name,
                'organizations' => $holders[$module->id] ?? 0,
                'share' => (int) round(($holders[$module->id] ?? 0) / $working->count() * 100),
            ])
            ->values()
            ->all();

        return ['organizations' => $working->count(), 'rows' => $rows];
    }

    /*
    |--------------------------------------------------------------------------
    | Locations
    |--------------------------------------------------------------------------
    */

    /**
     * Where the organizations are, from the profile their address is saved on.
     *
     * `places` are the exact points for the map — one per organization whose
     * address has been located (see App\Services\Geo\OrganizationLocator).
     * `points` count organizations per state for the region and state lists,
     * which need only the state. An organization with neither counts in the
     * total and nowhere else.
     *
     * @return array<string, mixed>
     */
    private function locations(): array
    {
        $profiles = OrganizationProfile::query()
            ->whereHas('organization')
            ->with('organization:id,uuid,organization_name')
            ->get();

        $byState = $profiles
            ->pluck('state_province')
            ->filter()
            ->map(fn (string $state) => $this->stateName($state))
            ->filter()
            ->countBy();

        $places = $profiles
            ->filter(fn (OrganizationProfile $profile) => $profile->hasCoordinates())
            ->map(fn (OrganizationProfile $profile) => [
                'uuid' => $profile->organization->uuid,
                'name' => $profile->organization->organization_name,
                'city' => $profile->city,
                'state' => $profile->state_province ? $this->stateName($profile->state_province) : null,
                'latitude' => $profile->latitude,
                'longitude' => $profile->longitude,
                // How far the point can be trusted: manual, address, pincode or city.
                'source' => $profile->location_source,
            ])
            ->values();

        return [
            'unit' => 'organizations',
            'organizations' => Organization::count(),
            'located' => $places->count(),
            'clinics' => null,
            'places' => $places->all(),
            'points' => $byState
                ->sortDesc()
                ->map(fn (int $total, string $state) => ['state' => $state, 'total' => $total])
                ->values()
                ->all(),
        ];
    }

    /**
     * One spelling per state. Owners type it themselves, so "uttar Pradesh"
     * and "Uttar Pradesh " must land on the same dot rather than two.
     */
    private function stateName(string $state): string
    {
        $name = Str::title(Str::lower(Str::squish($state)));

        return str_replace(' And ', ' and ', $name);
    }

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return PlatformAuditLog::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(self::ACTIVITY_SIZE)
            ->get()
            ->map(fn (PlatformAuditLog $entry) => [
                'id' => $entry->id,
                'event' => $entry->action,
                'entity_type' => $entry->entity_type,
                'text' => $this->sentence($entry),
                'actor_name' => $entry->actor_name,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** "Organization created: Ananya Clinic". */
    private function sentence(PlatformAuditLog $entry): string
    {
        $what = Str::ucfirst(Str::lower(Str::headline($entry->entity_type)));
        $line = "{$what} {$entry->action}";

        return $entry->entity_label ? "{$line}: {$entry->entity_label}" : $line;
    }
}
