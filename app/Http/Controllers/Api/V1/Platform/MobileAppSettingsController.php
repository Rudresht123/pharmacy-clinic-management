<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Platform\MobileServerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server address development phones use — typed here once, read by every
 * phone from Firebase, so a laptop that changed IP needs a new value saved
 * here rather than a code change and a rebuild.
 *
 * @group Platform > Mobile app
 */
class MobileAppSettingsController extends BaseApiController
{
    public function __construct(
        private readonly MobileServerAddress $server,
    ) {}

    /** The saved address, whether it reached Firebase, and this computer's own addresses. */
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->server->current($request->getPort()));
    }

    /** Save and publish. A blank address clears it. */
    public function update(Request $request): JsonResponse
    {
        $request->validate(['address' => ['nullable', 'string', 'max:255']]);

        $state = $this->server->save($request->input('address'), $request->getPort());

        $message = match (true) {
            $state['publish_error'] !== null => 'Saved, but it did not reach the phones: '.$state['publish_error'],
            $state['address'] === null => 'Cleared. Phones will find the server on their own again.',
            default => 'Saved. Phones use it the next time the app opens.',
        };

        return $this->ok($state, $message);
    }
}
