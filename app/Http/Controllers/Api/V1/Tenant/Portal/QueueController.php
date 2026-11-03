<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Services\Queue\PatientQueueStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the patient stands in the queue today.
 *
 * @group Patient Portal > Appointments
 */
class QueueController extends PortalController
{
    public function __construct(
        private readonly PatientQueueStatus $queue,
    ) {}

    /** Every visit today for this patient and their family. */
    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->queue->today($this->patient($request)));
    }

    /** One visit, for the screen an app polls while somebody waits. */
    public function show(Request $request, int $appointment): JsonResponse
    {
        $status = $this->queue->one($this->patient($request), $appointment);

        if ($status === null) {
            return $this->fail('That visit was not found.', 404);
        }

        return $this->ok($status);
    }
}
