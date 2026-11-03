<?php

namespace App\Http\Controllers\Api\V1\Display;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Middleware\ResolveQueueDisplay;
use App\Models\Tenant\QueueDisplay;
use App\Services\Queue\QueueDisplays;
use App\Services\Realtime\RealtimeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a television reads, and how it is let onto its channels.
 *
 * @group Queue Displays
 *
 * @unauthenticated
 */
class DisplayController extends BaseApiController
{
    public function __construct(
        private readonly QueueDisplays $displays,
    ) {}

    /**
     * The screen's whole state.
     *
     * Read once on opening, and again on a slow timer however good the socket
     * is, which is how a wall hears that it was switched off or reconfigured,
     * and how the management screen knows it is still on.
     */
    public function show(Request $request): JsonResponse
    {
        $display = $this->display($request);

        $this->displays->markSeen($display);

        return $this->ok($this->displays->state($display, $request->attributes->get('tenant.organization')));
    }

    /**
     * Sign one private channel for this screen.
     *
     * The television's equivalent of /broadcasting/auth, and the whole of what
     * stops it hearing a doctor who is not on it: a room's screen asking for the
     * next room's channel gets a 403, and so does anybody who has lifted a
     * channel name from somewhere else.
     */
    public function authorizeChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);

        $allowed = $this->displays->channels(
            $this->display($request),
            $request->attributes->get('tenant.organization'),
        );

        if (! in_array($data['channel_name'], $allowed, true)) {
            return $this->fail('This display does not show that queue.', 403);
        }

        return response()->json(['auth' => RealtimeConfig::sign($data['socket_id'], $data['channel_name'])]);
    }

    private function display(Request $request): QueueDisplay
    {
        return $request->attributes->get(ResolveQueueDisplay::ATTRIBUTE);
    }
}
