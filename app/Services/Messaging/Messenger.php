<?php

namespace App\Services\Messaging;

use App\Broadcasting\UserMessagesChannel;
use App\Events\Realtime\ConversationDelivered;
use App\Events\Realtime\ConversationRead;
use App\Events\Realtime\ConversationTyping;
use App\Events\Realtime\MessageSent;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Conversation;
use App\Models\Tenant\ConversationMessage;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\File;
use App\Models\Tenant\User;
use App\Services\Push\PushManager;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Doctor-to-patient messaging, for both sides.
 *
 * The staff and portal routes reach the same methods with a different
 * [Participant]; nothing here asks which app the request came from. Three
 * rules hold everywhere:
 *
 *   1. A conversation is OPENED only between a doctor and a patient who share
 *      an appointment that was not cancelled. Once open it stays usable — a
 *      thread does not vanish because the visit it started from was moved.
 *   2. Every read and write is narrowed to the participant's own threads
 *      (Participant::scope). A guessed id answers 404, not someone else's
 *      messages.
 *   3. Saving is the only thing that must succeed. Telling the other side —
 *      live over Reverb, and by push — happens after the commit and can fail
 *      without failing the send; the next fetch finds the message regardless.
 */
class Messenger
{
    /** What a phone camera or a scanner actually produces. */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,heic,heif,pdf,doc,docx';

    public const MAX_KILOBYTES = 20480;

    public const MAX_BODY = 4000;

    private const PAGE = 30;

    private const CATCH_UP = 100;

    public function __construct(
        private readonly PushManager $push,
    ) {}

    /**
     * The inbox: every thread with something in it, newest first.
     *
     * Fetching it is this phone receiving what is in it: each thread with a
     * message newer than this side's delivered pointer is marked delivered,
     * and the other side's single tick becomes two.
     *
     * @return array{rows: list<array<string, mixed>>, unread: int}
     */
    public function inbox(Organization $organization, Participant $me, ?string $search = null): array
    {
        $other = Conversation::otherSide($me->side);
        $readColumn = Conversation::readColumn($me->side);

        $conversations = $me->scope(Conversation::query())
            ->whereNotNull('last_message_at')
            ->with(['doctor.photograph', 'doctor.user', 'customer.user', 'latestMessage.file'])
            ->withCount(['messages as unread_count' => fn (Builder $query) => $query
                ->where('sender_side', $other)
                ->whereColumn('conversation_messages.id', '>', "conversations.{$readColumn}")])
            ->when(filled($search), fn (Builder $query) => $query->whereHas(
                $me->isDoctor() ? 'customer' : 'doctor',
                fn (Builder $who) => $who->where('name', 'ilike', '%'.trim((string) $search).'%'),
            ))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $deliveredColumn = Conversation::deliveredColumn($me->side);

        foreach ($conversations as $conversation) {
            $latest = (int) $conversation->latestMessage?->getKey();

            if ($latest > (int) $conversation->getAttribute($deliveredColumn)) {
                $this->markDelivered($organization, $me, $conversation, $latest);
            }
        }

        $rows = $conversations->map(fn (Conversation $conversation) => $this->conversationRow($me, $conversation))
            ->values()
            ->all();

        return ['rows' => $rows, 'unread' => (int) $conversations->sum('unread_count')];
    }

    /** Unread messages across every thread — the tab's badge. */
    public function unreadTotal(Participant $me): int
    {
        $other = Conversation::otherSide($me->side);
        $readColumn = Conversation::readColumn($me->side);

        return ConversationMessage::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->where($me->isDoctor() ? 'conversations.doctor_id' : 'conversations.customer_id', $me->isDoctor()
                ? $me->doctor->getKey()
                : $me->customer->getKey())
            ->where('conversation_messages.sender_side', $other)
            ->whereColumn('conversation_messages.id', '>', "conversations.{$readColumn}")
            ->count();
    }

    /** How many people the picker lists before it asks for a search instead. */
    private const CONTACTS = 100;

    /**
     * Who this participant may start a conversation with.
     *
     * The other side of every appointment they share that was not cancelled,
     * with when they were last seen and the thread id where one already
     * exists. Most recently seen first: a doctor with hundreds of patients is
     * nearly always writing to somebody from this week, and an alphabetical
     * first page buries them. `total` is everybody who matches, so the screen
     * can say "248 patients" while showing the first hundred.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function contacts(Participant $me, ?string $search = null): array
    {
        $term = trim((string) $search);
        $today = now()->toDateString();

        if ($me->isDoctor()) {
            $doctorId = $me->doctor->getKey();

            $matching = Customer::query()
                ->active()
                ->whereIn('id', $this->visits()->where('doctor_id', $doctorId)->select('customer_id'))
                ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('code', 'ilike', "%{$term}%")
                    ->orWhere('phone', 'ilike', "%{$term}%")));

            $people = (clone $matching)
                ->with('user')
                ->addSelect(['last_visit_on' => $this->visits()
                    ->selectRaw('max(appointment_date)')
                    ->whereColumn('appointments.customer_id', 'customers.id')
                    ->where('doctor_id', $doctorId)
                    ->where('appointment_date', '<=', $today)])
                ->orderByRaw('last_visit_on desc nulls last')
                ->orderBy('name')
                ->limit(self::CONTACTS)
                ->get();

            $threads = Conversation::query()
                ->where('doctor_id', $doctorId)
                ->whereIn('customer_id', $people->modelKeys())
                ->pluck('id', 'customer_id');

            return [
                'rows' => $people->map(fn (Customer $customer) => $this->patientCard($customer) + [
                    'conversation_id' => $threads[$customer->getKey()] ?? null,
                    'last_visit_on' => $this->day($customer->getAttribute('last_visit_on')),
                ])->values()->all(),
                'total' => $matching->count(),
            ];
        }

        $customerId = $me->customer->getKey();

        $matching = Doctor::query()
            ->active()
            ->whereIn('id', $this->visits()->where('customer_id', $customerId)->select('doctor_id'))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('specialisation', 'ilike', "%{$term}%")));

        $doctors = (clone $matching)
            ->with(['photograph', 'user'])
            ->addSelect(['last_visit_on' => $this->visits()
                ->selectRaw('max(appointment_date)')
                ->whereColumn('appointments.doctor_id', 'doctors.id')
                ->where('customer_id', $customerId)
                ->where('appointment_date', '<=', $today)])
            ->orderByRaw('last_visit_on desc nulls last')
            ->orderBy('name')
            ->limit(self::CONTACTS)
            ->get();

        $threads = Conversation::query()
            ->where('customer_id', $customerId)
            ->whereIn('doctor_id', $doctors->modelKeys())
            ->pluck('id', 'doctor_id');

        return [
            'rows' => $doctors->map(fn (Doctor $doctor) => $this->doctorCard($doctor) + [
                'conversation_id' => $threads[$doctor->getKey()] ?? null,
                'last_visit_on' => $this->day($doctor->getAttribute('last_visit_on')),
            ])->values()->all(),
            'total' => $matching->count(),
        ];
    }

    /** A date column read through a subquery, as `Y-m-d` or null. */
    private function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    /**
     * The thread with [counterpartId] — found, or opened now.
     *
     * Refused unless the two share a visit. A patient may not open one with a
     * doctor who has no login, because nobody would ever read it; a doctor may
     * write to a patient who has not installed the app yet, since they will
     * see it the day they do.
     */
    public function open(Participant $me, int $counterpartId): Conversation
    {
        [$doctorId, $customerId] = $me->isDoctor()
            ? [(int) $me->doctor->getKey(), $counterpartId]
            : [$counterpartId, (int) $me->customer->getKey()];

        $existing = Conversation::query()
            ->where('doctor_id', $doctorId)
            ->where('customer_id', $customerId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $shareAVisit = $this->visits()
            ->where('doctor_id', $doctorId)
            ->where('customer_id', $customerId)
            ->exists();

        if (! $shareAVisit) {
            throw new HttpException(403, $me->isDoctor()
                ? 'You can only message patients who have had an appointment with you.'
                : 'You can only message doctors you have had an appointment with.');
        }

        if ($me->isDoctor()) {
            Customer::query()->active()->findOrFail($customerId);
        } else {
            $doctor = Doctor::query()->active()->with('user')->findOrFail($doctorId);

            if (! $this->hasLogin($doctor->user)) {
                throw new HttpException(422, "{$doctor->name} is not on the app yet, so a message would not reach them. Please call the clinic instead.");
            }
        }

        return Conversation::query()->firstOrCreate([
            'doctor_id' => $doctorId,
            'customer_id' => $customerId,
        ]);
    }

    /** One thread as the participant sees it, for the chat screen's header. */
    public function describe(Participant $me, Conversation $conversation): array
    {
        $conversation->loadMissing(['doctor.photograph', 'doctor.user', 'customer.user', 'latestMessage.file']);

        $other = Conversation::otherSide($me->side);

        $conversation->setAttribute('unread_count', $conversation->messages()
            ->where('sender_side', $other)
            ->where('id', '>', (int) $conversation->getAttribute(Conversation::readColumn($me->side)))
            ->count());

        return $this->conversationRow($me, $conversation);
    }

    /**
     * Messages, oldest first.
     *
     * The latest page by default; [before] reaches further back, and [after]
     * catches up on what arrived while the socket was away.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool}
     */
    public function page(Conversation $conversation, ?int $before = null, ?int $after = null): array
    {
        $query = $conversation->messages()->with('file');

        if ($after !== null) {
            $messages = $query->where('id', '>', $after)->orderBy('id')->limit(self::CATCH_UP + 1)->get();
            $hasMore = $messages->count() > self::CATCH_UP;

            return [
                'messages' => $messages->take(self::CATCH_UP)->map(fn ($m) => $this->messageRow($m))->values()->all(),
                'has_more' => $hasMore,
            ];
        }

        $messages = $query
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE + 1)
            ->get();

        return [
            'messages' => $messages->take(self::PAGE)->reverse()->map(fn ($m) => $this->messageRow($m))->values()->all(),
            'has_more' => $messages->count() > self::PAGE,
        ];
    }

    /**
     * Say something. Text, a file, or both — at least one.
     *
     * @return array<string, mixed> the message as sent
     */
    public function send(
        Organization $organization,
        Participant $me,
        Conversation $conversation,
        ?string $body,
        ?UploadedFile $upload,
    ): array {
        $this->assertStillOpen($conversation);

        $body = $body === null ? null : trim($body);
        $body = $body === '' ? null : $body;

        if ($body === null && $upload === null) {
            throw new HttpException(422, 'Write a message or attach a file.');
        }

        /*
         * The bytes go down before the transaction, under the PRIVATE disk,
         * namespaced by organization and thread. Laravel's generated name is
         * kept; the client's is recorded on the row for display only, since a
         * client-chosen path is how a path gets traversed.
         */
        $stored = $upload?->store("conversations/{$organization->getKey()}/{$conversation->getKey()}", 'local');

        $message = DB::connection(TenantConnectionService::CONNECTION)->transaction(
            function () use ($me, $conversation, $body, $upload, $stored) {
                $file = $upload === null ? null : File::create([
                    'file_name' => mb_substr($upload->getClientOriginalName(), 0, 191),
                    'file_path' => $stored,
                    'disk' => 'local',
                    'mime_type' => $upload->getClientMimeType(),
                    'file_size' => $upload->getSize(),
                    'extension' => $upload->getClientOriginalExtension(),
                ]);

                $message = ConversationMessage::create([
                    'conversation_id' => $conversation->getKey(),
                    'sender_side' => $me->side,
                    'sender_user_id' => $me->user->getKey(),
                    'body' => $body,
                    'file_id' => $file?->getKey(),
                ]);

                // Whoever writes has read everything up to what they wrote.
                $conversation->forceFill([
                    'last_message_at' => $message->created_at,
                    Conversation::readColumn($me->side) => $message->getKey(),
                ])->save();

                return $message->setRelation('file', $file);
            },
        );

        $row = $this->messageRow($message);

        $this->announce($organization, $me, $conversation, $row);

        return $row;
    }

    /**
     * The participant has seen everything up to [upTo] (or all of it).
     *
     * Only ever moves forward, and only as far as the thread actually goes, so
     * a stale or invented id cannot unread anything or mark the future read.
     */
    public function markRead(Organization $organization, Participant $me, Conversation $conversation, ?int $upTo = null): int
    {
        $column = Conversation::readColumn($me->side);
        $latest = (int) $conversation->messages()->max('id');
        $target = min($upTo ?? $latest, $latest);
        $current = (int) $conversation->getAttribute($column);

        if ($target <= $current) {
            return $current;
        }

        $delivered = Conversation::deliveredColumn($me->side);

        // Read on this phone is received on this phone: blue ticks never sit
        // above a grey "delivered" that lags behind them.
        $conversation->forceFill([
            $column => $target,
            $delivered => max($target, (int) $conversation->getAttribute($delivered)),
        ])->save();

        $this->broadcast(new ConversationRead(
            $organization->uuid,
            $this->loginsOf(Conversation::otherSide($me->side), $conversation),
            ['conversation_id' => $conversation->getKey(), 'side' => $me->side, 'last_read_id' => $target],
        ));

        return $target;
    }

    /**
     * The participant's phone has received everything up to [upTo] (or all
     * of it) — the second grey tick on the other side.
     *
     * Forward only and never past the end, for the same reasons as reading.
     */
    public function markDelivered(Organization $organization, Participant $me, Conversation $conversation, ?int $upTo = null): int
    {
        $column = Conversation::deliveredColumn($me->side);
        $latest = (int) $conversation->messages()->max('id');
        $target = min($upTo ?? $latest, $latest);
        $current = (int) $conversation->getAttribute($column);

        if ($target <= $current) {
            return $current;
        }

        $conversation->forceFill([$column => $target])->save();

        $this->broadcast(new ConversationDelivered(
            $organization->uuid,
            $this->loginsOf(Conversation::otherSide($me->side), $conversation),
            ['conversation_id' => (int) $conversation->getKey(), 'side' => $me->side, 'last_delivered_id' => $target],
        ));

        return $target;
    }

    /**
     * The participant is writing in this thread right now.
     *
     * Only ever live — there is nothing to fetch later, and nobody to tell
     * when the other side has no login or the socket is down.
     */
    public function typing(Organization $organization, Participant $me, Conversation $conversation): void
    {
        $this->broadcast(new ConversationTyping(
            $organization->uuid,
            $this->loginsOf(Conversation::otherSide($me->side), $conversation),
            ['conversation_id' => (int) $conversation->getKey(), 'side' => $me->side],
        ));
    }

    /** The private channel this participant's own live updates arrive on. */
    public function channelFor(Organization $organization, Participant $me): string
    {
        return 'private-'.UserMessagesChannel::name($organization->uuid, (int) $me->user->getKey());
    }

    /** A thread can be written to while both people are still on the books. */
    private function assertStillOpen(Conversation $conversation): void
    {
        $conversation->loadMissing(['doctor', 'customer']);

        $doctor = $conversation->doctor;

        if ($doctor === null || $doctor->trashed() || ! $doctor->is_active) {
            throw new HttpException(422, 'This doctor is no longer available to message.');
        }

        if ($conversation->customer === null || ! $conversation->customer->is_active) {
            throw new HttpException(422, 'This patient is no longer available to message.');
        }
    }

    /** Live to both sides, then a push to the other. Never fails the send. */
    private function announce(Organization $organization, Participant $me, Conversation $conversation, array $row): void
    {
        $this->broadcast(new MessageSent(
            $organization->uuid,
            array_values(array_unique([
                ...$this->loginsOf(Conversation::DOCTOR, $conversation),
                ...$this->loginsOf(Conversation::PATIENT, $conversation),
            ])),
            [
                'conversation_id' => $conversation->getKey(),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'message' => $row,
            ],
        ));

        $recipients = $this->loginsOf(Conversation::otherSide($me->side), $conversation);

        if ($recipients === []) {
            return;
        }

        $recipient = $me->isDoctor() ? $conversation->customer : $conversation->doctor;

        /*
         * Who wrote, and what kind of thing — never the words. A lock screen
         * is read by whoever is holding the phone, and a doctor's message is
         * clinical; the app shows the text once it is opened.
         */
        $what = match (true) {
            $row['attachment'] === null => 'Sent you a message',
            $row['attachment']['is_image'] => 'Sent you a photo',
            default => 'Sent you a file',
        };

        try {
            $this->push->queueToUsers(
                $organization,
                $recipients,
                (string) $recipient?->name,
                $me->name(),
                $what,
                ['type' => 'message.received', 'conversation_id' => (int) $conversation->getKey()],
                array_filter([
                    'recipient_name' => $recipient?->name,
                    'customer_id' => $me->isDoctor() ? $conversation->customer_id : null,
                ], fn ($value) => $value !== null),
            );
        } catch (\Throwable $e) {
            Log::warning('messaging.push-failed', [
                'conversation_id' => $conversation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Reverb being down is a slower chat, not a failed one. */
    private function broadcast(object $event): void
    {
        if (($event->userIds ?? []) === []) {
            return;
        }

        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('messaging.broadcast-failed', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The active logins on one side of a thread.
     *
     * @return list<int>
     */
    private function loginsOf(string $side, Conversation $conversation): array
    {
        $conversation->loadMissing(['doctor.user', 'customer']);

        if ($side === Conversation::DOCTOR) {
            $login = $conversation->doctor?->user;

            return $this->hasLogin($login) ? [(int) $login->getKey()] : [];
        }

        return $conversation->customer ? $this->push->loginsFor($conversation->customer) : [];
    }

    private function hasLogin(?User $user): bool
    {
        return $user !== null && $user->is_active && ! $user->trashed();
    }

    /** Appointments that count as having seen each other. */
    private function visits(): Builder
    {
        return Appointment::query()->where('status', '<>', Appointment::STATUS_CANCELLED);
    }

    /** @return array<string, mixed> */
    private function conversationRow(Participant $me, Conversation $conversation): array
    {
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->getKey(),
            'counterpart' => $me->isDoctor()
                ? $this->patientCard($conversation->customer)
                : $this->doctorCard($conversation->doctor),
            'last_message' => $latest ? $this->messageRow($latest) : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread' => (int) ($conversation->unread_count ?? 0),
            // How far the other side has read and received: the sender's
            // ticks are one grey, two grey, or two blue from these.
            'counterpart_read_id' => $this->counterpartRead($me, $conversation),
            'counterpart_delivered_id' => $this->counterpartDelivered($me, $conversation),
        ];
    }

    public function counterpartRead(Participant $me, Conversation $conversation): int
    {
        return (int) $conversation->getAttribute(Conversation::readColumn(Conversation::otherSide($me->side)));
    }

    /** Never behind what they have read — a message read was received. */
    public function counterpartDelivered(Participant $me, Conversation $conversation): int
    {
        return max(
            $this->counterpartRead($me, $conversation),
            (int) $conversation->getAttribute(Conversation::deliveredColumn(Conversation::otherSide($me->side))),
        );
    }

    /** @return array<string, mixed> */
    private function messageRow(ConversationMessage $message): array
    {
        $file = $message->file;

        return [
            'id' => $message->getKey(),
            'conversation_id' => $message->conversation_id,
            'sender_side' => $message->sender_side,
            'body' => $message->body,
            'sent_at' => $message->created_at?->toIso8601String(),

            // No URL: each side downloads through its own authenticated route,
            // by message id, so a link can never outlive the check behind it.
            'attachment' => $file === null ? null : [
                'name' => $file->file_name,
                'mime' => $file->mime_type,
                'size' => (int) $file->file_size,
                'is_image' => str_starts_with((string) $file->mime_type, 'image/'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function patientCard(?Customer $customer): array
    {
        return [
            'kind' => Conversation::PATIENT,
            'id' => $customer?->getKey(),
            'name' => $customer?->name ?? 'Patient',
            'subtitle' => $customer?->code,
            'photo_url' => null,
            'on_app' => $this->hasLogin($customer?->user),
        ];
    }

    /** @return array<string, mixed> */
    private function doctorCard(?Doctor $doctor): array
    {
        return [
            'kind' => Conversation::DOCTOR,
            'id' => $doctor?->getKey(),
            'name' => $doctor?->name ?? 'Doctor',
            'subtitle' => $doctor?->specialisation,
            'photo_url' => $doctor?->photograph?->url,
            'on_app' => $this->hasLogin($doctor?->user),
        ];
    }

    /** Streams an attachment's bytes, or null when the file has gone. */
    public function download(ConversationMessage $message): ?StreamedResponse
    {
        $file = $message->file;

        if ($file === null || ! Storage::disk($file->disk ?: 'local')->exists($file->file_path)) {
            return null;
        }

        $disk = Storage::disk($file->disk ?: 'local');

        // Inline, so a photo opens in an image view rather than a save dialog.
        return $disk->response($file->file_path, $file->file_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
