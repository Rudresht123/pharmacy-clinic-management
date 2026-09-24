<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\AutomationRuleResource;
use App\Http\Resources\Tenant\MessageLogResource;
use App\Http\Resources\Tenant\MessageTemplateResource;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Services\Email\EmailMessage;
use App\Services\Tenant\CommunicationOverview;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Everything one channel's screen needs, in one request.
 *
 * A dashboard that fetched the connection, the figures, the templates, the
 * rules and the log separately would paint in five stages and be wrong in
 * between — the setup checklist is derived from the same state the template
 * list shows, and the two arriving apart is how a screen tells a patient it is
 * ready before it is.
 */
class CommunicationController extends BaseApiController
{
    public function __construct(
        private readonly CommunicationOverview $overview,
    ) {}

    public function show(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, CommunicationChannel::CHANNELS, true), 404);

        $days = (int) $request->integer('days', CommunicationOverview::DEFAULT_DAYS);
        $connection = CommunicationChannel::forChannel($channel);

        return $this->ok([
            'channel' => $channel,

            'account' => [
                'display_name' => $connection->display_name,
                'handle' => $connection->handle,
                'provider' => $connection->provider,
                'is_connected' => $connection->is_connected,
                'is_verified' => $connection->is_verified,

                // Its own column, not a setting — the checklist reads it, and
                // burying it in the JSON bag would put one step of setup
                // somewhere the others are not.
                'webhook_configured' => $connection->webhook_configured,

                'connected_at' => $connection->connected_at?->toIso8601String(),
                'settings' => $connection->settings ?? [],
            ],

            'setup' => $this->overview->setupSteps($connection),
            'figures' => $this->overview->figures($channel, $days),

            // Counted from the same log the figures are, in the same request:
            // a chart that disagrees with the number above it is worse than no
            // chart, and two requests over a moving window will disagree.
            'analytics' => $this->overview->analytics($channel, $days),

            'templates' => MessageTemplateResource::collection(
                MessageTemplate::query()->forChannel($channel)->get()
            ),

            'rules' => AutomationRuleResource::collection(
                AutomationRule::query()->forChannel($channel)->with('template')->get()
            ),

            /*
             * Enough to scroll through rather than enough to glance at. The
             * panel shows about eight at a time and the log is the one thing
             * on this screen somebody reads DOWN — looking for the message
             * that did not arrive — so ten would be a scrollbar that moves
             * twice. The full history is behind "View all".
             */
            'recent' => MessageLogResource::collection(
                MessageLog::query()
                    ->forChannel($channel)
                    ->real()
                    ->latest('created_at')
                    ->limit(30)
                    ->get()
            ),
        ]);
    }

    /**
     * The connection itself — what patients see this arriving from.
     *
     * `communication.manage`, not `.view`: changing the number a clinic
     * messages from is an organisation decision, not a branch one.
     */
    public function update(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, CommunicationChannel::CHANNELS, true), 404);

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:150'],
            'handle' => ['nullable', 'string', 'max:150'],
            'provider' => ['nullable', 'string', 'max:100'],
            'is_connected' => ['boolean'],
            'is_verified' => ['boolean'],
            'webhook_configured' => ['boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $connection = CommunicationChannel::forChannel($channel);

        $connection->fill($validated);
        $connection->updated_by = Auth::guard('web')->id();

        // Connecting stamps when; disconnecting clears it, so the card cannot
        // claim a connection date for an account that is not connected.
        if ($request->boolean('is_connected') && $connection->connected_at === null) {
            $connection->connected_at = Carbon::now();
        }

        if (! $request->boolean('is_connected', $connection->is_connected)) {
            $connection->connected_at = null;
        }

        $connection->save();

        return $this->ok(null, 'Connection saved');
    }

    /**
     * Whether this clinic's credentials actually work.
     *
     * Branches on the channel, which it previously did not — every channel
     * ran the WhatsApp check, so testing email reported on WhatsApp's account.
     *
     * Answers with the normalized result and NOTHING ELSE. A provider's own
     * error text is for the server log: a Digiware URL carries its token in
     * the query string and an SMTP error quotes the transport DSN, which
     * carries the password.
     *
     * The outcome is stamped on the channel so the settings screen can say
     * when it last worked, which is the question somebody has when messages
     * quietly stopped arriving three days ago.
     */
    public function testConnection(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, CommunicationChannel::CHANNELS, true), 404);

        $organization = $request->attributes->get('tenant.organization');

        [$success, $message, $provider] = match ($channel) {
            CommunicationChannel::EMAIL => (function () use ($organization) {
                $result = emailer($organization)->testConnection();

                return [$result->success, $result->message, 'smtp'];
            })(),

            CommunicationChannel::WHATSAPP => (function () use ($organization) {
                $result = whatsapp($organization)->testConnection();

                return [$result->success, $result->message, $result->provider];
            })(),

            default => [false, 'This channel has no provider to test yet.', $channel],
        };

        CommunicationChannel::forChannel($channel)->forceFill([
            'verified_at' => $success ? Carbon::now() : null,
            'verification_error' => $success ? null : mb_substr($message, 0, 500),
        ])->save();

        return $this->ok([
            'success' => $success,
            'provider' => $provider,
        ], $message);
    }

    /**
     * Send a test to one address or several, to prove the channel works.
     *
     * Several because checking a connection usually means checking it reaches
     * the people who will rely on it — the front desk, the owner's phone, the
     * doctor's — and doing that one at a time is three round trips to learn
     * one fact.
     *
     * Capped at ten. A test proves a connection; past a handful it stops being
     * a test and becomes an unaudited broadcast with no audience behind it.
     *
     * Every row is flagged `is_test`, so they count towards the setup
     * checklist without moving the delivery rates the dashboard reports.
     */
    public function test(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, CommunicationChannel::CHANNELS, true), 404);

        $validated = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:10'],
            'recipients.*' => ['required', 'string', 'max:150', 'distinct'],
            'message_template_id' => [
                'nullable',
                Rule::exists(MessageTemplate::class, 'id')->whereNull('deleted_at'),
            ],
        ], [
            'recipients.required' => 'Add at least one address to send the test to.',
            'recipients.max' => 'A test goes to at most ten addresses.',
            'recipients.*.distinct' => 'That address is in the list twice.',
        ]);

        $template = isset($validated['message_template_id'])
            ? MessageTemplate::query()->find($validated['message_template_id'])
            : null;

        $now = Carbon::now();
        $actor = Auth::guard('web')->id();

        $marks = [
            'message_template_id' => $template?->id,
            // A free-text test has no template, and a log row that names
            // nothing is a row support staff cannot identify later.
            'template_name' => $template?->name ?? 'Test message',
            'is_test' => true,
            'created_by' => $actor,
        ];

        if ($channel === CommunicationChannel::WHATSAPP) {
            /*
             * Through the manager, so a test exercises the SAME path a real
             * appointment reminder takes — provider selection, template
             * resolution, the queue, the retry rules. A test that took a
             * shortcut would prove only that the shortcut works.
             *
             * Deliberately NOT wrapped in a transaction. The manager dispatches
             * as it writes, and the queue runs on a different connection: a
             * worker that picked the job up mid-transaction would look for a
             * log row that had not been committed yet.
             */
            $manager = whatsapp($request->attributes->get('tenant.organization'));

            foreach ($validated['recipients'] as $recipient) {
                $manager->queue(
                    $template !== null
                        ? WhatsAppMessage::template(trim($recipient), $template->name)
                        : WhatsAppMessage::text(trim($recipient), 'Test message from '.config('app.name')),
                    extra: $marks,
                );
            }
        } elseif ($channel === CommunicationChannel::EMAIL) {
            /*
             * Through the manager, so a test exercises the SAME path a real
             * appointment confirmation takes — the clinic's own SMTP settings,
             * the queue, the retry rules. A test that took a shortcut would
             * prove only that the shortcut works.
             *
             * Deliberately NOT wrapped in a transaction: the manager dispatches
             * as it writes and the queue runs on a different connection, so a
             * worker that picked the job up mid-transaction would look for a
             * log row that had not been committed yet.
             */
            $emailer = emailer($request->attributes->get('tenant.organization'));

            foreach ($validated['recipients'] as $recipient) {
                $emailer->queue(
                    EmailMessage::make(
                        to: trim($recipient),
                        subject: $template?->subject
                            ?? $template?->name
                            ?? 'Test message from '.config('app.name'),
                        body: $template?->content
                            ?? 'This is a test message. Your email channel is working.',
                        template: $template?->name,
                    ),
                    extra: $marks,
                );
            }
        } else {
            /*
             * SMS has no provider behind it yet. All or nothing: a partial
             * test is worse than a failed one, because half the phones ring,
             * the checklist ticks, and nobody knows which half.
             */
            DB::connection('organization')->transaction(
                function () use ($validated, $channel, $template, $now, $marks) {
                    foreach ($validated['recipients'] as $recipient) {
                        MessageLog::query()->create($marks + [
                            'channel' => $channel,
                            'recipient' => trim($recipient),
                            'subject' => $template?->subject,
                            'status' => MessageLog::QUEUED,
                            'scheduled_for' => $now,
                        ]);
                    }
                }
            );
        }

        $count = count($validated['recipients']);

        return $this->ok(
            null,
            $count === 1
                ? 'Test message queued'
                : "Test message queued for {$count} addresses",
        );
    }
}
