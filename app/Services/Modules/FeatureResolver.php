<?php

namespace App\Services\Modules;

use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\OrganizationModuleSetting;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Whether a module runs at a branch — the formula, in one place.
 *
 * Three answers from three people, combined one way only:
 *
 *   1. SOLD          `organization_modules`, master — the super admin.
 *                    Read through ModuleAccess, which also knows about start
 *                    and expiry dates and modules retired platform-wide.
 *   2. ORGANISATION  `organization_module_settings`, tenant — the owner, for
 *                    the whole network.
 *   3. BRANCH        `location_modules`, tenant — the owner, per branch.
 *
 *   effective = sold AND switched on for the organisation AND not off here
 *
 * A BRANCH CAN ONLY SUBTRACT. Only its `false` rows are read. A `true` row is a
 * record of what somebody chose, kept so a screen can say "on here
 * deliberately" rather than "inherited", but it never adds anything the two
 * levels above did not already allow — which is what makes "the organisation
 * switched it off, a branch switched it back on" impossible rather than merely
 * refused at the door.
 *
 * Core modules are always on. No row at either tenant level can take one away:
 * an organisation without its customer register is not a smaller organisation,
 * it is a broken one.
 *
 * Permission::modulesAt() is the cached front door to this and what the rest
 * of the application calls. This class holds no state, so asking it twice asks
 * the database twice; go through Permission on anything that runs per row.
 */
class FeatureResolver
{
    /**
     * Features that are not modules of their own but the meeting of several.
     *
     * Code-defined, like the registry: a derived feature exists because some
     * screen behaves differently when both halves are present, and the list of
     * those screens is a property of the code. On only where EVERY module
     * listed is on at that branch.
     *
     * @var array<string, list<string>>
     */
    public const DERIVED = [
        /*
         * The pharmacy counter seeing patients the doctor has just finished
         * with. A pharmacy-only business was never sold the OPD and has no
         * consultations to hand over; a branch running OPD with no counter has
         * nobody to hand them to.
         */
        'opd_pharmacy_handoff' => ['appointments', 'pharmacy'],
    ];

    /** Why a module is in the state it is — see explain(). */
    public const CORE = 'core';

    public const NOT_PURCHASED = 'not_purchased';

    public const ORGANIZATION_OFF = 'organization_off';

    public const BRANCH_OFF = 'branch_off';

    public const BRANCH_ON = 'branch_on';

    public const INHERITED = 'inherited';

    public const ENABLED = 'enabled';

    public function __construct(
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * The module keys usable at a branch, in registry order.
     *
     * A null branch is the organisation-wide answer: levels one and two only,
     * which is what the owner and head office work under.
     *
     * @return list<string>
     */
    public function enabled(Organization $organization, ?int $locationId = null): array
    {
        $running = $this->organizationEnabled($organization);

        if ($locationId === null) {
            return $running;
        }

        $off = $this->withoutCore(
            LocationModule::query()
                ->where('location_id', $locationId)
                ->where('is_enabled', false)
                ->pluck('module_key')
                ->all()
        );

        return array_values(array_diff($running, $off));
    }

    /**
     * Every module, and every derived feature, as on or off at a branch.
     *
     * The shape a client wants: a key it has never heard of reads as absent,
     * and one it has reads true or false rather than "in the list or not".
     *
     * @return array<string, bool>
     */
    public function forBranch(Organization $organization, ?int $locationId = null): array
    {
        return $this->toMap($this->enabled($organization, $locationId));
    }

    /**
     * A list of running modules, as the map forBranch() answers with.
     *
     * Public and pure so Permission can build the map from the list it has
     * already cached, rather than asking the database a second time.
     *
     * @param  list<string>  $enabled
     * @return array<string, bool>
     */
    public function toMap(array $enabled): array
    {
        $map = [];

        foreach (ModuleRegistry::keys() as $key) {
            $map[$key] = in_array($key, $enabled, true);
        }

        foreach (self::DERIVED as $key => $needs) {
            $map[$key] = array_diff($needs, $enabled) === [];
        }

        return $map;
    }

    /**
     * Levels one and two: sold, and not switched off for the organisation.
     *
     * @return list<string>
     */
    public function organizationEnabled(Organization $organization): array
    {
        $this->assertConnectedTo($organization);

        return array_values(array_diff(
            $this->modules->enabled($organization),
            $this->withoutCore(OrganizationModuleSetting::offKeys()),
        ));
    }

    /**
     * What a branch has said about each module, where it has said anything.
     *
     * Absent means it inherits. Read whole, true rows included, because this
     * is for showing somebody what was chosen — enabled() is what decides.
     *
     * @return array<string, bool>
     */
    public function overridesAt(int $locationId): array
    {
        return LocationModule::query()
            ->where('location_id', $locationId)
            ->pluck('is_enabled', 'module_key')
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    /**
     * Every module with each level's answer and the one that decided it.
     *
     * For the screens that have to explain themselves. Must agree with
     * enabled() on every row — the feature test walks both to prove it.
     *
     * @return list<array{
     *     key: string, name: string, group: string, is_core: bool,
     *     purchased: bool, organization_enabled: bool,
     *     branch_override: bool|null, effective: bool, reason: string,
     * }>
     */
    public function explain(Organization $organization, ?int $locationId = null): array
    {
        $this->assertConnectedTo($organization);

        $purchased = $this->modules->enabled($organization);
        $organizationOff = $this->withoutCore(OrganizationModuleSetting::offKeys());
        $overrides = $locationId === null ? [] : $this->overridesAt($locationId);

        $rows = [];

        foreach (ModuleRegistry::all() as $module) {
            $key = $module['key'];
            $isPurchased = in_array($key, $purchased, true);
            $override = $locationId === null ? null : ($overrides[$key] ?? null);

            [$effective, $reason] = match (true) {
                $module['is_core'] => [true, self::CORE],
                ! $isPurchased => [false, self::NOT_PURCHASED],
                in_array($key, $organizationOff, true) => [false, self::ORGANIZATION_OFF],
                $override === false => [false, self::BRANCH_OFF],
                $override === true => [true, self::BRANCH_ON],
                $locationId !== null => [true, self::INHERITED],
                default => [true, self::ENABLED],
            };

            $rows[] = [
                'key' => $key,
                'name' => $module['name'],
                'group' => $module['group'],
                'is_core' => $module['is_core'],
                'purchased' => $isPurchased,
                'organization_enabled' => $isPurchased && ! in_array($key, $organizationOff, true),
                'branch_override' => $module['is_core'] ? null : $override,
                'effective' => $effective,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /**
     * The communication module, with the channels a message can go out on.
     *
     * WhatsApp, SMS and email are channels INSIDE the communication module,
     * not modules of their own, so they are reported under it. A channel is
     * usable only where the module runs AND the clinic has connected an
     * account for it — a connected WhatsApp number at an organisation that has
     * since switched communication off sends nothing.
     *
     * @param  list<string>  $enabled  what runs at the branch being asked about
     * @return array{enabled: bool, channels: array<string, bool>}
     */
    public function communicationFor(array $enabled): array
    {
        $running = in_array('communication', $enabled, true);

        $connected = $running
            ? CommunicationChannel::query()
                ->where('is_connected', true)
                ->pluck('channel')
                ->all()
            : [];

        $channels = [];

        foreach (CommunicationChannel::CHANNELS as $channel) {
            $channels[$channel] = in_array($channel, $connected, true);
        }

        return ['enabled' => $running, 'channels' => $channels];
    }

    /**
     * Refuse to answer about one organisation from another's database.
     *
     * Levels two and three live in the tenant database, which is whichever
     * one the `organization` connection points at right now — not necessarily
     * the organisation named in the call. Answering anyway would apply one
     * clinic's switches to another clinic's entitlements and look perfectly
     * plausible doing it. Loud rather than wrong.
     */
    private function assertConnectedTo(Organization $organization): void
    {
        $connected = Config::get('database.connections.'.TenantConnectionService::CONNECTION.'.database');

        if ($connected !== $organization->database_name) {
            throw new LogicException(sprintf(
                'Asked about the modules of organization [%s] while connected to [%s].',
                $organization->database_name ?? '-',
                $connected ?? 'no tenant',
            ));
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function withoutCore(array $keys): array
    {
        return array_values(array_diff($keys, ModuleRegistry::coreKeys()));
    }
}
