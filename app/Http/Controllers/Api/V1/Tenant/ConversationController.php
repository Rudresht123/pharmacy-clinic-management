<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Middleware\EnsurePortalPatient;
use App\Models\Tenant\Conversation;
use App\Models\Tenant\ConversationMessage;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Doctor-to-patient messages, from either end.
 *
 * Mounted twice — under /tenant/messages for a doctor's login and under
 * /tenant/portal/messages for a patient's — and the same methods serve both.
 * Which side is asking comes from the login (see [participant]), never from
 * the request, and every conversation is checked to be that side's own before
 * anything is read from it or written to it.
 *
 * @group Tenant > Messages
 */
class ConversationController extends BaseApiController
{
    public function __construct(
        private readonly Messenger $messenger,
    ) {}

    /** The inbox, newest first, with unread counts and the live channel. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $me = $this->participant($request);
        $inbox = $this->messenger->inbox(
            $request->attributes->get('tenant.organization'),
            $me,
            $request->string('q')->toString() ?: null,
        );

        return $this->ok($inbox['rows'], meta: $this->meta($request, $me) + ['unread' => $inbox['unread']]);
    }

    /** Just the badge — cheap enough to ask on every tab switch. */
    public function unread(Request $request): JsonResponse
    {
        $me = $this->participant($request);

        return $this->ok(['unread' => $this->messenger->unreadTotal($me)]);
    }

    /** Who a new conversation can be started with. */
    public function contacts(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $me = $this->participant($request);
        $contacts = $this->messenger->contacts($me, $request->string('q')->toString() ?: null);

        return $this->ok($contacts['rows'], meta: ['total' => $contacts['total']]);
    }

    /**
     * Find or open the thread with one doctor (for a patient) or one patient
     * (for a doctor). Body: `doctor_id` or `customer_id` — the other side.
     */
    public function store(Request $request): JsonResponse
    {
        $me = $this->participant($request);
        $key = $me->isDoctor() ? 'customer_id' : 'doctor_id';

        $validated = $request->validate([$key => ['required', 'integer', 'min:1']]);

        $conversation = $this->messenger->open($me, (int) $validated[$key]);

        return $this->ok($this->messenger->describe($me, $conversation), meta: $this->meta($request, $me));
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        return $this->ok($this->messenger->describe($me, $conversation), meta: $this->meta($request, $me));
    }

    /**
     * Messages, oldest first: the latest page, `before` an id for older ones,
     * or `after` an id to catch up after the connection dropped.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        $page = $this->messenger->page(
            $conversation,
            $request->filled('before') ? $request->integer('before') : null,
            $request->filled('after') ? $request->integer('after') : null,
        );

        return $this->ok($page['messages'], meta: [
            'has_more' => $page['has_more'],
            'counterpart_read_id' => $this->messenger->counterpartRead($me, $conversation),
            'counterpart_delivered_id' => $this->messenger->counterpartDelivered($me, $conversation),
        ]);
    }

    /** Text (`body`), a file (`file`), or both. Multipart when there is a file. */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:'.Messenger::MAX_BODY, 'required_without:file'],
            'file' => [
                'nullable',
                'file',
                'mimes:'.Messenger::ALLOWED_MIMES,
                'max:'.Messenger::MAX_KILOBYTES,
                'required_without:body',
            ],
        ], [
            'body.required_without' => 'Write a message or attach a file.',
            'file.required_without' => 'Write a message or attach a file.',
            'file.mimes' => 'Send a photo (JPG, PNG, WEBP, HEIC), a PDF or a Word document.',
            'file.max' => 'That file is larger than 20 MB.',
        ]);

        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        $message = $this->messenger->send(
            $request->attributes->get('tenant.organization'),
            $me,
            $conversation,
            $request->input('body'),
            $request->file('file'),
        );

        return $this->created($message);
    }

    /** Everything up to `up_to` (or all of it) has been seen. */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate(['up_to' => ['nullable', 'integer', 'min:1']]);

        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        $lastRead = $this->messenger->markRead(
            $request->attributes->get('tenant.organization'),
            $me,
            $conversation,
            $request->filled('up_to') ? $request->integer('up_to') : null,
        );

        return $this->ok(['last_read_id' => $lastRead]);
    }

    /** This phone has received everything up to `up_to` (or all of it). */
    public function delivered(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate(['up_to' => ['nullable', 'integer', 'min:1']]);

        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        $lastDelivered = $this->messenger->markDelivered(
            $request->attributes->get('tenant.organization'),
            $me,
            $conversation,
            $request->filled('up_to') ? $request->integer('up_to') : null,
        );

        return $this->ok(['last_delivered_id' => $lastDelivered]);
    }

    /** "I am writing" — relayed live to the other side, never stored. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $this->participant($request);
        $this->ensureOwn($me, $conversation);

        $this->messenger->typing($request->attributes->get('tenant.organization'), $me, $conversation);

        return $this->ok(['typing' => true]);
    }

    /** An attachment's bytes, after the same ownership check as everything else. */
    public function attachment(Request $request, ConversationMessage $message): StreamedResponse|JsonResponse
    {
        $me = $this->participant($request);
        $this->ensureOwn($me, $message->conversation);

        return $this->messenger->download($message)
            ?? $this->fail('The file is no longer available.', 404);
    }

    /**
     * Which side of a conversation this login is.
     *
     * A patient on the portal routes (EnsurePortalPatient has already found
     * their record), or a doctor on the staff routes. Anybody else — a
     * receptionist given the capability by mistake — has no side to be on.
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
            abort(403, 'Messages are between doctors and their patients, and you are not signed in as either.');
        }

        return Participant::doctor($user, $doctor);
    }

    /** Somebody else's conversation answers as if it did not exist. */
    private function ensureOwn(Participant $me, ?Conversation $conversation): void
    {
        if ($conversation === null || ! $me->owns($conversation)) {
            abort(404, 'Conversation not found.');
        }
    }

    /** @return array<string, mixed> */
    private function meta(Request $request, Participant $me): array
    {
        return [
            'side' => $me->side,
            'channel' => $this->messenger->channelFor($request->attributes->get('tenant.organization'), $me),
        ];
    }
}
