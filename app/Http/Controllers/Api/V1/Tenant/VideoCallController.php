<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Middleware\EnsurePortalPatient;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Models\Tenant\VideoCallSignal;
use App\Services\Messaging\Participant;
use App\Services\Telehealth\VideoCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Video consultations, from either end.
 *
 * Mounted twice — under /tenant for a doctor's login and under /tenant/portal
 * for a patient's — and the same methods serve both, as messages do. Which
 * side is asking comes from the login (see [participant]), never from the
 * request, and every call is checked to be that side's own.
 *
 * The media itself is peer-to-peer WebRTC between the two phones; these
 * routes ring, answer, hang up, and carry the handshake that connects them.
 *
 * @group Tenant > Video consultations
 */
class VideoCallController extends BaseApiController
{
    /** An SDP for audio and video is a few KB; this leaves room and no more. */
    private const MAX_SDP = 30000;

    public function __construct(
        private readonly VideoCalls $calls,
    ) {}

    /**
     * Ring the patient for one of today's video visits. Doctor only.
     *
     * Answers with the live call if one is already ringing or connected for
     * this visit, so a second tap or a reopened app rejoins rather than rings
     * again.
     */
    public function ring(Request $request, Appointment $appointment): JsonResponse
    {
        $me = $this->participant($request);
        $call = $this->calls->ring($this->organization($request), $me, $appointment);

        return $this->ok($this->calls->describeFor($me, $call), 'Calling');
    }

    /**
     * The ring or call this login is in right now, and the channel to hear
     * the next one on. What the app asks on opening.
     */
    public function current(Request $request): JsonResponse
    {
        $me = $this->participant($request);
        $call = $this->calls->current($me);

        return $this->ok(
            $call ? $this->calls->describeFor($me, $call) : null,
            meta: ['channel' => $this->calls->channelFor($this->organization($request), $me)],
        );
    }

    public function show(Request $request, int $call): JsonResponse
    {
        $me = $this->participant($request);

        return $this->ok($this->calls->describeFor($me, $this->calls->find($me, $call)));
    }

    /** Pick up. Patient only. */
    public function accept(Request $request, int $call): JsonResponse
    {
        $me = $this->participant($request);
        $answered = $this->calls->accept($this->organization($request), $me, $this->calls->find($me, $call));

        return $this->ok($this->calls->describeFor($me, $answered), 'Connected');
    }

    /** Turn the ring down. Patient only. */
    public function decline(Request $request, int $call): JsonResponse
    {
        $me = $this->participant($request);
        $declined = $this->calls->decline($this->organization($request), $me, $this->calls->find($me, $call));

        return $this->ok($this->calls->describe($declined));
    }

    /**
     * Hang up. `reason: no_answer` from the doctor's phone when its ring ran
     * out, so the call is recorded as missed rather than given up on.
     */
    public function end(Request $request, int $call): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', Rule::in(['hangup', 'no_answer', 'failed'])],
        ]);

        $me = $this->participant($request);
        $ended = $this->calls->end(
            $this->organization($request),
            $me,
            $this->calls->find($me, $call),
            $validated['reason'] ?? null,
        );

        return $this->ok($this->calls->describe($ended));
    }

    /**
     * Hand one step of the WebRTC handshake to the other phone: an `offer` or
     * `answer` with its `sdp`, or a batch of ICE `candidates`.
     */
    public function signal(Request $request, int $call): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(VideoCallSignal::TYPES)],
            'sdp' => [
                Rule::requiredIf(fn () => $request->input('type') !== VideoCallSignal::CANDIDATES),
                Rule::prohibitedIf(fn () => $request->input('type') === VideoCallSignal::CANDIDATES),
                'string',
                'max:'.self::MAX_SDP,
            ],
            'candidates' => [
                Rule::requiredIf(fn () => $request->input('type') === VideoCallSignal::CANDIDATES),
                Rule::prohibitedIf(fn () => $request->input('type') !== VideoCallSignal::CANDIDATES),
                'array',
                'min:1',
                'max:50',
            ],
            'candidates.*.candidate' => ['required', 'string', 'max:1000'],
            'candidates.*.sdp_mid' => ['nullable', 'string', 'max:64'],
            'candidates.*.sdp_mline_index' => ['nullable', 'integer', 'min:0', 'max:64'],
        ]);

        $me = $this->participant($request);

        $payload = $validated['type'] === VideoCallSignal::CANDIDATES
            ? ['candidates' => array_map(fn (array $candidate) => [
                'candidate' => $candidate['candidate'],
                'sdp_mid' => $candidate['sdp_mid'] ?? null,
                'sdp_mline_index' => $candidate['sdp_mline_index'] ?? null,
            ], $validated['candidates'])]
            : ['sdp' => $validated['sdp']];

        $signal = $this->calls->signal(
            $this->organization($request),
            $me,
            $this->calls->find($me, $call),
            $validated['type'],
            $payload,
        );

        return $this->created(['id' => (int) $signal->getKey()]);
    }

    /** The other phone's handshake steps past `after`, oldest first. */
    public function signals(Request $request, int $call): JsonResponse
    {
        $validated = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);

        $me = $this->participant($request);
        $found = $this->calls->find($me, $call);

        return $this->ok(
            $this->calls->signalsFor($me, $found, (int) ($validated['after'] ?? 0))
                ->map(fn (VideoCallSignal $signal) => [
                    'id' => (int) $signal->getKey(),
                    'type' => $signal->type,
                ] + $signal->payload)
                ->values(),
            meta: ['status' => $found->status],
        );
    }

    /**
     * Which end of a call this login is.
     *
     * A patient on the portal routes (EnsurePortalPatient has already found
     * their record), or a doctor on the staff routes. Anybody else — somebody
     * at the desk given the capability by mistake — has no end to be on.
     */
    private function participant(Request $request): Participant
    {
        $user = $request->user();
        $patient = $request->attributes->get(EnsurePortalPatient::ATTRIBUTE);

        if ($user instanceof User && $patient instanceof Customer) {
            return Participant::patient($user, $patient);
        }

        $doctor = $user instanceof User && $user->userable_type === Doctor::class
            ? Doctor::query()->find($user->userable_id)
            : null;

        if ($doctor === null) {
            abort(403, 'Video consultations are between doctors and their patients, and you are not signed in as either.');
        }

        return Participant::doctor($user, $doctor);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('tenant.organization');
    }
}
