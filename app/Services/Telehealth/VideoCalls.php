<?php

namespace App\Services\Telehealth;

use App\Broadcasting\UserCallsChannel;
use App\Events\Realtime\VideoCallSignalled;
use App\Events\Realtime\VideoCallUpdated;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;
use App\Models\Tenant\VideoCall;
use App\Models\Tenant\VideoCallSignal;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\Participant;
use App\Services\Opd\BookingService;
use App\Services\Opd\VisitWorkflow;
use App\Services\Push\PushManager;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Video consultations, for both ends.
 *
 * The doctor rings, the patient answers or does not, either hangs up. The
 * audio and video never pass through here — the phones exchange them directly
 * over WebRTC — but everything that decides whether a call may happen does:
 *
 *   1. Only a visit booked as VIDEO can be rung, only by its own doctor, and
 *      only on its own day. A doctor cannot video-call a patient who booked to
 *      come in, or ring somebody about a visit that is not today.
 *   2. Every call is read through the asker's own side (Participant::scope),
 *      so a guessed id answers 404 rather than somebody else's call.
 *   3. Saving is the only thing that must succeed. Telling the other phone —
 *      over Reverb and by push — happens after the commit and may fail; the
 *      phone that missed the news finds the call on its next fetch.
 *
 * ANSWERING IS ARRIVING. A video patient never stands at the desk, so nobody
 * would ever check them in or call them through, and the doctor could never
 * open the write-up. When the patient picks up, the visit takes those steps
 * through BookingService and VisitWorkflow — the same doors the desk uses, so
 * the queue, the token and the billing trigger behave exactly as for somebody
 * who walked in.
 */
class VideoCalls
{
    /** How many handshake steps one fetch returns. A call needs a handful. */
    private const SIGNAL_PAGE = 100;

    public function __construct(
        private readonly PushManager $push,
        private readonly IceServers $iceServers,
        private readonly Messenger $messenger,
        private readonly BookingService $booking,
        private readonly VisitWorkflow $workflow,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The doctor
    |--------------------------------------------------------------------------
    */

    /**
     * Ring the patient for a video visit.
     *
     * A second tap while the first ring is still going — or a doctor coming
     * back to a call their app dropped — gets that same call back rather than
     * a second one beside it.
     */
    public function ring(Organization $organization, Participant $me, Appointment $visit): VideoCall
    {
        if (! $me->isDoctor() || (int) $visit->doctor_id !== (int) $me->doctor->getKey()) {
            throw new HttpException(404, 'Appointment not found.');
        }

        if (! $visit->isVideo()) {
            throw new HttpException(422, 'This visit was booked to come in to the clinic. Only a video visit can be called.');
        }

        if (! $visit->appointment_date?->isToday()) {
            throw new HttpException(422, 'A video visit can only be called on its own day.');
        }

        if (in_array($visit->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            throw new HttpException(422, $visit->status === Appointment::STATUS_CANCELLED
                ? 'This visit was cancelled.'
                : 'This visit was marked as a no-show.');
        }

        $patient = $visit->customer()->first();

        if ($patient === null || $this->push->loginsFor($patient) === []) {
            throw new HttpException(422, sprintf(
                '%s is not signed in to the app, so a call cannot reach them. Please phone them instead.',
                $patient?->name ?? 'This patient',
            ));
        }

        // Rings nobody answered stop counting before anything below asks
        // who is busy.
        $this->expireLapsed(VideoCall::query()->where(fn (Builder $query) => $query
            ->where('doctor_id', $visit->doctor_id)
            ->orWhere('customer_id', $visit->customer_id)));

        $existing = VideoCall::query()->live()->where('appointment_id', $visit->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $busy = VideoCall::query()->live()->where('doctor_id', $visit->doctor_id)->with('customer')->first();

        if ($busy !== null) {
            throw new HttpException(409, sprintf(
                'You are already on a call with %s. End it before starting another.',
                $busy->customer?->name ?? 'another patient',
            ));
        }

        if (VideoCall::query()->live()->where('customer_id', $visit->customer_id)->exists()) {
            throw new HttpException(409, "{$patient->name} is on another call right now. Please try again in a few minutes.");
        }

        try {
            $call = VideoCall::query()->create([
                'appointment_id' => $visit->id,
                'doctor_id' => $visit->doctor_id,
                'customer_id' => $visit->customer_id,
                'doctor_user_id' => $me->user->getKey(),
                'status' => VideoCall::RINGING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two taps landed together; the other one rang. Hand that back.
            return VideoCall::query()->live()->where('appointment_id', $visit->id)->firstOrFail();
        }

        $this->tell($organization, $call, $this->push->loginsFor($patient));

        try {
            $this->push->queue(
                $organization,
                $patient,
                (string) ($me->doctor->name ?? 'Your doctor'),
                'Video consultation: tap to join the call.',
                ['type' => 'video_call.incoming', 'call_id' => (int) $call->getKey(), 'appointment_id' => (int) $visit->id],
            );
        } catch (\Throwable $e) {
            // The live channel already told an open app; a push is the backup.
            Log::warning('Video call push not queued', ['call' => $call->getKey(), 'error' => $e->getMessage()]);
        }

        return $call;
    }

    /*
    |--------------------------------------------------------------------------
    | The patient
    |--------------------------------------------------------------------------
    */

    /** Pick up, and bring the visit into the room. */
    public function accept(Organization $organization, Participant $me, VideoCall $call): VideoCall
    {
        $this->assertPatient($me);

        if ($call->isActive()) {
            if ((int) $call->patient_user_id === (int) $me->user->getKey()) {
                return $call;
            }

            throw new HttpException(409, 'This call was answered on another phone.');
        }

        if ($call->ringHasLapsed()) {
            $this->lapse($organization, $call);

            throw new HttpException(409, 'This call was missed. Your doctor can call again.');
        }

        if (! $call->isRinging()) {
            throw new HttpException(409, 'This call has ended.');
        }

        $answered = DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($call, $me) {
            $locked = VideoCall::query()->lockForUpdate()->findOrFail($call->getKey());

            if (! $locked->isRinging()) {
                return null;
            }

            $locked->forceFill([
                'status' => VideoCall::ACTIVE,
                'answered_at' => now(),
                'patient_user_id' => $me->user->getKey(),
            ])->save();

            return $locked;
        });

        if ($answered === null) {
            // Ended, or answered on another phone, between the read and the lock.
            throw new HttpException(409, 'This call has ended.');
        }

        $this->bringIn($answered);

        $this->tell($organization, $answered, $this->everyoneOn($answered));

        return $answered;
    }

    /** Turn the ring down. */
    public function decline(Organization $organization, Participant $me, VideoCall $call): VideoCall
    {
        $this->assertPatient($me);

        if (! $call->isRinging()) {
            return $call;
        }

        return $this->finish($organization, $call, VideoCall::DECLINED, VideoCall::BY_PATIENT);
    }

    /*
    |--------------------------------------------------------------------------
    | Either side
    |--------------------------------------------------------------------------
    */

    /**
     * Put the phone down.
     *
     * Before anybody answered, the doctor hanging up is giving up — `missed`
     * when it was because nobody picked up (`no_answer`, sent by the doctor's
     * phone when its ring runs out), `cancelled` otherwise — and the patient
     * hanging up is declining. After, it is simply the end of the call.
     */
    public function end(Organization $organization, Participant $me, VideoCall $call, ?string $reason = null): VideoCall
    {
        if (! $call->isLive()) {
            return $call;
        }

        if ($call->isRinging()) {
            return $me->isDoctor()
                ? $this->finish(
                    $organization,
                    $call,
                    $reason === 'no_answer' ? VideoCall::MISSED : VideoCall::CANCELLED,
                    $reason === 'no_answer' ? VideoCall::BY_SYSTEM : VideoCall::BY_DOCTOR,
                )
                : $this->finish($organization, $call, VideoCall::DECLINED, VideoCall::BY_PATIENT);
        }

        return $this->finish($organization, $call, VideoCall::ENDED, $me->side);
    }

    /**
     * Pass one step of the WebRTC handshake to the other phone.
     *
     * @param  array<string, mixed>  $payload  `sdp` for an offer or answer, `candidates` for candidates
     */
    public function signal(Organization $organization, Participant $me, VideoCall $call, string $type, array $payload): VideoCallSignal
    {
        if (! $call->isActive()) {
            throw new HttpException(409, 'The call is not connected.');
        }

        $signal = VideoCallSignal::query()->create([
            'video_call_id' => $call->getKey(),
            'from_side' => $me->side,
            'type' => $type,
            'payload' => $payload,
        ]);

        $to = $me->isDoctor() ? $call->patient_user_id : $call->doctor_user_id;

        if ($to !== null) {
            $this->broadcast(new VideoCallSignalled(
                $organization->uuid,
                [(int) $to],
                (int) $call->getKey(),
                (int) $signal->getKey(),
                $type,
            ));
        }

        return $signal;
    }

    /**
     * The other phone's handshake steps, past the last one this phone saw.
     *
     * @return Collection<int, VideoCallSignal>
     */
    public function signalsFor(Participant $me, VideoCall $call, int $after = 0): Collection
    {
        return $call->signals()
            ->where('from_side', '<>', $me->side)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::SIGNAL_PAGE)
            ->get();
    }

    /** The asker's own call, or 404 — a guessed id reads as missing. */
    public function find(Participant $me, int $id): VideoCall
    {
        $call = $this->scope($me, VideoCall::query())->find($id);

        if ($call === null) {
            throw new HttpException(404, 'Call not found.');
        }

        return $call;
    }

    /**
     * The ring or call this person is in right now, if any.
     *
     * What a phone asks when it opens — from a push, or after being away — so
     * a ring that arrived while the socket was down is still answered.
     */
    public function current(Participant $me): ?VideoCall
    {
        $this->expireLapsed($this->scope($me, VideoCall::query()));

        return $this->scope($me, VideoCall::query())->live()->latest('id')->first();
    }

    /** The full name of this login's calls channel, `private-` prefix included. */
    public function channelFor(Organization $organization, Participant $me): string
    {
        return 'private-'.UserCallsChannel::name($organization->uuid, (int) $me->user->getKey());
    }

    /**
     * A call as either phone sees it, minus anything per-login.
     *
     * @return array<string, mixed>
     */
    public function describe(VideoCall $call): array
    {
        $call->loadMissing(['doctor.photograph', 'customer', 'appointment']);

        return [
            'id' => (int) $call->getKey(),
            'status' => $call->status,
            'appointment_id' => (int) $call->appointment_id,
            'doctor' => [
                'id' => (int) $call->doctor_id,
                'name' => $call->doctor?->name,
                'specialisation' => $call->doctor?->specialisation,
                'photo_url' => $call->doctor?->photograph?->url,
            ],
            'patient' => [
                'id' => (int) $call->customer_id,
                'name' => $call->customer?->name,
                'code' => $call->customer?->code,
                'age' => $call->customer?->date_of_birth?->age,
                'gender' => $call->customer?->gender,
            ],
            'appointment' => [
                'date' => $call->appointment?->appointment_date?->toDateString(),
                'slot_at' => $call->appointment?->slot_at ? substr((string) $call->appointment->slot_at, 0, 5) : null,
                'token_no' => $call->appointment?->token_no,
            ],
            'rang_at' => $call->created_at?->toIso8601String(),
            'ring_expires_at' => $call->ringExpiresAt()?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'ended_by' => $call->ended_by,
        ];
    }

    /**
     * The call as THIS phone needs it: its side, the servers it may route
     * media through, and the thread for chatting and sharing files during it.
     *
     * @return array<string, mixed>
     */
    public function describeFor(Participant $me, VideoCall $call): array
    {
        return $this->describe($call) + [
            'side' => $me->side,
            'ice_servers' => $this->iceServers->for((int) $me->user->getKey()),
            'conversation_id' => $this->conversationFor($me, $call),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Inside
    |--------------------------------------------------------------------------
    */

    /**
     * Check in, call through and start the consultation — whichever of those
     * the visit has not had yet.
     *
     * Each step is the desk's or the doctor's own method, so the token is
     * issued, the queue moves and an advance-payment clinic bills exactly as
     * for somebody standing at reception. The doctor who rang is recorded as
     * calling them and starting, because that is who did.
     *
     * Nothing here may cost the patient their call. A visit the workflow will
     * not move — already completed, say, and being rung back about a result —
     * is logged and left as it is.
     */
    private function bringIn(VideoCall $call): void
    {
        $visit = Appointment::query()->find($call->appointment_id);

        if ($visit === null || $visit->consultationStarted()) {
            return;
        }

        $doctor = $call->doctor_user_id ? User::query()->find($call->doctor_user_id) : null;

        try {
            if ($visit->status === Appointment::STATUS_BOOKED) {
                $visit = $this->booking->checkIn($visit);
            }

            if ($visit->isWaiting()) {
                $visit = $this->workflow->call($visit, $doctor);
            }

            if ($visit->isCalled()) {
                $this->workflow->startConsultation($visit, $doctor);
            }
        } catch (RuntimeException $e) {
            Log::info('Video call connected without moving the visit', [
                'call' => $call->getKey(),
                'appointment' => $visit->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Close the call, clear its handshake, and tell both ends. */
    private function finish(Organization $organization, VideoCall $call, string $status, string $by): VideoCall
    {
        $closed = DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($call, $status, $by) {
            $locked = VideoCall::query()->lockForUpdate()->findOrFail($call->getKey());

            if (! $locked->isLive()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $status,
                'ended_at' => $status === VideoCall::MISSED ? ($locked->ringExpiresAt()?->min(now()) ?? now()) : now(),
                'ended_by' => $by,
            ])->save();

            // Both phones' addresses, no longer needed by anybody.
            $locked->signals()->delete();

            return $locked;
        });

        $this->tell($organization, $closed, $this->everyoneOn($closed));

        return $closed;
    }

    /** A ring nobody answered in time, closed as missed. */
    private function lapse(Organization $organization, VideoCall $call): void
    {
        $this->finish($organization, $call, VideoCall::MISSED, VideoCall::BY_SYSTEM);
    }

    /**
     * Close every lapsed ring in [$query] as missed.
     *
     * Done when somebody next asks rather than on a timer: nothing has to be
     * running for a stale ring to stop being answerable, and the doctor's own
     * phone has already given up and said so at the same moment.
     *
     * @param  Builder<VideoCall>  $query
     */
    private function expireLapsed(Builder $query): void
    {
        $lapsed = $query->clone()
            ->where('status', VideoCall::RINGING)
            ->where('created_at', '<', now()->subSeconds(VideoCall::RING_SECONDS))
            ->get();

        foreach ($lapsed as $call) {
            $locked = VideoCall::query()->find($call->getKey());

            if ($locked !== null && $locked->ringHasLapsed()) {
                $locked->forceFill([
                    'status' => VideoCall::MISSED,
                    'ended_at' => $locked->ringExpiresAt(),
                    'ended_by' => VideoCall::BY_SYSTEM,
                ])->save();

                $locked->signals()->delete();
            }
        }
    }

    /**
     * Every login with a stake in the call: the doctor who rang, and all the
     * patient's phones — so a second phone stops ringing once one answers.
     *
     * @return list<int>
     */
    private function everyoneOn(VideoCall $call): array
    {
        $patient = $call->customer()->first();

        return array_values(array_unique(array_filter([
            $call->doctor_user_id ? (int) $call->doctor_user_id : null,
            ...($patient ? $this->push->loginsFor($patient) : []),
        ])));
    }

    /** @param  list<int>  $userIds */
    private function tell(Organization $organization, VideoCall $call, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->broadcast(new VideoCallUpdated($organization->uuid, $userIds, $this->describe($call)));
    }

    /**
     * Send, and survive a Reverb that is not there. The change is saved; a
     * phone that missed the news sees it on its next fetch.
     */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Video call broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The doctor-patient thread, for chat and files during the call. Null when
     * it cannot be opened — the call goes ahead without the chat.
     */
    private function conversationFor(Participant $me, VideoCall $call): ?int
    {
        try {
            return (int) $this->messenger
                ->open($me, $me->isDoctor() ? (int) $call->customer_id : (int) $call->doctor_id)
                ->getKey();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Builder<VideoCall>  $query
     * @return Builder<VideoCall>
     */
    private function scope(Participant $me, Builder $query): Builder
    {
        return $me->isDoctor()
            ? $query->where('doctor_id', $me->doctor->getKey())
            : $query->where('customer_id', $me->customer->getKey());
    }

    private function assertPatient(Participant $me): void
    {
        if ($me->isDoctor()) {
            throw new HttpException(403, 'Only the patient can answer the call.');
        }
    }
}
