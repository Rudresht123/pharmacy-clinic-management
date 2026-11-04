<?php

namespace App\Http\Controllers\Api\V1\Tenant\Concerns;

use App\Models\Platform\Organization;
use App\Services\Permissions\Permission;
use App\Support\Modules\ScreenTicks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One tick per channel's screen.
 *
 * The route lets in anybody holding any channel's tick, because it cannot see which
 * channel is being asked about. This is where that is answered.
 */
trait AsksAboutChannels
{
    private function holds(Request $request, string $tick): bool
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = Auth::guard('web')->user();

        return $organization instanceof Organization
            && $user !== null
            && app(Permission::class)->allows($organization, $user, $tick);
    }

    /** May this person open this channel's screen? */
    private function mayOpenChannel(Request $request, string $channel): bool
    {
        return $this->holds($request, ScreenTicks::COMMUNICATION_CHANNELS[$channel]);
    }

    /**
     * Whose templates and rules this person may list.
     *
     * Every channel for whoever manages communication: a campaign and a test
     * message are written from those lists. The same for the Recipients screen,
     * which is every channel's messages in one place.
     *
     * @return list<string>
     */
    private function readableChannels(Request $request): array
    {
        $channels = array_keys(ScreenTicks::COMMUNICATION_CHANNELS);

        if ($this->holds($request, 'communication.manage') || $this->holds($request, 'communication.recipients')) {
            return $channels;
        }

        return array_values(array_filter($channels, fn (string $channel) => $this->mayOpenChannel($request, $channel)));
    }
}
