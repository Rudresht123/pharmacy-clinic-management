<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Platform\Organization;
use App\Models\Platform\PlatformSetting;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use App\Services\WhatsApp\Providers\LogWhatsAppProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one door into WhatsApp.
 *
 * Business code says what it wants sent and to whom. Everything else — which
 * provider this clinic uses, what its credentials are, what the provider calls
 * the template, how its answer maps onto a status, what gets logged and what
 * gets retried — happens here or below.
 *
 * QUEUED BY DEFAULT. An appointment must not wait on somebody else's API, and
 * a provider having a bad afternoon must not turn into a booking that cannot
 * be made. `sendNow()` exists for the cases that genuinely need an answer —
 * a connection test, a test message somebody is watching for.
 *
 * The organization must be NAMED rather than assumed. A queue worker serves
 * many tenants in one process, and ambient "current organization" state is
 * exactly how a message gets sent from the wrong clinic's account.
 */
class WhatsAppManager
{
    private ?Organization $organization = null;

    /** @var array{provider_key: ?string, credentials: array<string, mixed>}|null */
    private ?array $platformSettings = null;

    public function __construct(
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * Whose WhatsApp account to use.
     *
     * Returns a CLONE rather than mutating: the manager is resolved from the
     * container and two callers in one request must not be able to change
     * each other's target.
     */
    public function forOrganization(Organization|int $organization): self
    {
        $resolved = $organization instanceof Organization
            ? $organization
            : Organization::query()->findOrFail($organization);

        $clone = clone $this;
        $clone->organization = $resolved;

        return $clone;
    }

    /**
     * Queue a template send, and answer with the log row that will carry it.
     *
     * The row is written HERE, at status queued, before the job runs. That way
     * a message exists in the log from the moment it was asked for — a queue
     * that never drains leaves evidence rather than silence.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function sendTemplate(
        string $phone,
        string $template,
        array $variables = [],
        ?array $header = null,
        array $buttons = [],
        ?int $customerId = null,
    ): MessageLog {
        $message = WhatsAppMessage::template(
            phone: $phone,
            template: $template,
            variables: $variables,
            header: $header,
            buttons: $buttons,
        );

        return $this->queue($message, $customerId);
    }

    /**
     * Write the log row and hand the job its id.
     *
     * The job carries the ORGANIZATION and the LOG ID and nothing else. Not
     * the message, because the row already holds it; and not the credentials,
     * because a serialized job body sits in the database in the clear.
     *
     * `$extra` is for columns the caller owns rather than the message does —
     * `is_test`, `created_by`, which template row it came from. They have to
     * be written in the SAME insert: the worker may pick the job up before the
     * next statement runs, and a test message that reaches the log without its
     * `is_test` flag is a test message counted in the clinic's delivery rate.
     *
     * @param  array<string, mixed>  $extra
     */
    public function queue(WhatsAppMessage $message, ?int $customerId = null, array $extra = []): MessageLog
    {
        $organization = $this->organization();

        $log = MessageLog::query()->create($extra + [
            'channel' => 'whatsapp',
            'provider' => $this->providerKey(),
            'customer_id' => $customerId,
            'recipient' => $message->normalizedPhone(),
            'template_name' => $message->template,
            'status' => MessageLog::QUEUED,
            'scheduled_for' => Carbon::now(),

            // The keys but never the values. A delivery log is read by
            // support staff, and the values are patient names, amounts and
            // appointment times.
            'request_payload' => config('whatsapp.store_payloads')
                ? $message->toLogContext()
                : null,
        ]);

        $queue = config('whatsapp.queue');

        // The values ride on the job instead, whose row the queue deletes the
        // moment it finishes. So does the message shape: the log row records a
        // template NAME, which a free-text message does not have.
        SendWhatsAppMessage::dispatch($organization->getKey(), $log->id, $message->variables, [
            'type' => $message->type,
            'body' => $message->body,
            'media_url' => $message->mediaUrl,
            'caption' => $message->caption,
        ])
            ->onConnection($queue['connection'])
            ->onQueue($queue['queue']);

        return $log;
    }

    /**
     * Send without queueing, and update the log in place.
     *
     * For the paths that need an answer now. Everything else should queue.
     */
    public function sendNow(WhatsAppMessage $message, MessageLog $log): WhatsAppResponse
    {
        $provider = $this->provider();

        $prepared = $message->type === WhatsAppMessage::TEMPLATE
            ? $this->resolve($message, $provider->key())
            : $message;

        if ($prepared === null) {
            return $this->record($log, WhatsAppResponse::failed(
                $provider->key(),
                "No approved '{$message->template}' template for this provider.",
                'template',
            ));
        }

        $response = match ($message->type) {
            WhatsAppMessage::TEXT => $provider->sendText($prepared),
            WhatsAppMessage::MEDIA => $provider->sendMedia($prepared),
            default => $provider->sendTemplate($prepared),
        };

        return $this->record($log, $response);
    }

    /**
     * Whether this clinic's credentials work, without messaging anybody.
     */
    public function testConnection(): WhatsAppResponse
    {
        return $this->provider()->testConnection();
    }

    /**
     * The provider this clinic sends through.
     *
     * Per-clinic settings win; the config default is the fallback, and the
     * fallback is `log`, which refuses to run in production. A clinic that has
     * configured nothing must not quietly borrow somebody else's account.
     */
    public function provider(): WhatsAppProviderInterface
    {
        $channel = $this->channel();
        $key = $this->providerKey();

        $configured = config("whatsapp.providers.{$key}");

        if ($configured === null) {
            Log::warning('[whatsapp] unknown provider configured', ['provider' => $key]);

            return new LogWhatsAppProvider;
        }

        $credentials = $this->credentialsFor($key, $channel);

        // `log` takes no credentials, and asking it for some would be asking
        // every future credential-less provider for some too.
        return $credentials === null
            ? app($configured['driver'])
            : app($configured['driver'], ['credentials' => $credentials]);
    }

    /**
     * Which provider sends, decided by the platform.
     *
     * The platform setting wins over config because it is the one an
     * administrator can change without a deploy; a clinic's own key wins over
     * both, for the rare clinic that brings its own account.
     */
    public function providerKey(): string
    {
        return $this->channel()?->provider_key
            ?? ($this->platform()['provider_key'] ?? null)
            ?: config('whatsapp.default');
    }

    /**
     * The credentials to send with, in order of who may set them.
     *
     * THREE LAYERS, each overriding the one before:
     *
     *   1. config/.env       — the developer's, for local work
     *   2. platform settings — the SUPERADMIN's, and normally the real ones:
     *                          there is one commercial account with the
     *                          provider and one number patients see
     *   3. the clinic's row  — only for a clinic that brings its own account
     *
     * Merged rather than either/or, so a layer that sets only a token still
     * gets the base URL from the one beneath it instead of nothing.
     *
     * @return array<string, mixed>|null
     */
    private function credentialsFor(string $key, ?CommunicationChannel $channel): ?array
    {
        $defaults = config("whatsapp.providers.{$key}.credentials");

        if ($defaults === null) {
            return null;
        }

        $platform = $this->platform();

        return array_merge(
            $defaults,
            $this->present($platform['provider_key'] === $key ? $platform['credentials'] : []),
            $this->present($channel?->credentials ?? []),
        );
    }

    /**
     * A layer's contribution: only the keys it actually set.
     *
     * Blank is not a value. Without this an empty box on a settings screen
     * would blank out the layer beneath rather than defer to it.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The platform-wide provider setting, read once per manager.
     *
     * Memoized because this sits on the path of every single send, and a
     * campaign to two thousand patients must not be two thousand reads of one
     * unchanging row.
     *
     * @return array{provider_key: ?string, credentials: array<string, mixed>}
     */
    private function platform(): array
    {
        if ($this->platformSettings !== null) {
            return $this->platformSettings;
        }

        try {
            $setting = PlatformSetting::forKey(PlatformSetting::WHATSAPP);

            return $this->platformSettings = [
                'provider_key' => $setting->value['provider_key'] ?? null,
                'credentials' => $setting->secret ?? [],
            ];
        } catch (\Throwable $exception) {
            // The table is unreachable — during an early migration, say. The
            // config layer still works, so sending degrades rather than stops.
            Log::warning('[whatsapp] could not read platform settings', [
                'detail' => $exception->getMessage(),
            ]);

            return $this->platformSettings = ['provider_key' => null, 'credentials' => []];
        }
    }

    /** The message, told what this provider calls its template. */
    private function resolve(WhatsAppMessage $message, string $provider): ?WhatsAppMessage
    {
        $resolved = $this->templates->resolve((string) $message->template, $provider);

        if ($resolved === null) {
            return null;
        }

        return $message->resolvedFor($resolved['name'], $resolved['order']);
    }

    /**
     * Write the provider's answer onto the log row.
     *
     * One place, so every path — queued, immediate, retried — leaves the same
     * shape behind, and the delivery log means the same thing whoever wrote it.
     */
    public function record(MessageLog $log, WhatsAppResponse $response): WhatsAppResponse
    {
        $now = Carbon::now();

        $log->forceFill([
            'provider' => $response->provider,
            'status' => $response->status,
            'provider_message_id' => $response->providerMessageId ?? $log->provider_message_id,
            'error_code' => $response->errorCode,
            'failure_reason' => $response->success ? null : mb_substr($response->message, 0, 500),
            'attempts' => $log->attempts + 1,
            'sent_at' => $response->success ? ($log->sent_at ?? $now) : $log->sent_at,
            'failed_at' => $response->success ? null : $now,
            'response_payload' => config('whatsapp.store_payloads')
                ? $this->redact($response->raw)
                : null,
        ])->save();

        return $response;
    }

    /**
     * Strip anything that must never be written down.
     *
     * Recursive, because a provider that echoes the request back nests it, and
     * a token one level down is just as much a credential in the database.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $secrets = array_map('strtolower', config('whatsapp.redact', []));

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->redact($value);

                continue;
            }

            if (in_array(strtolower((string) $key), $secrets, true)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }

    /**
     * The clinic's WhatsApp channel row.
     *
     * Null where the tenant connection is not pointed anywhere — which the
     * caller has to have arranged, because the manager cannot know which
     * database it is supposed to be reading.
     */
    private function channel(): ?CommunicationChannel
    {
        try {
            return CommunicationChannel::forChannel(CommunicationChannel::WHATSAPP);
        } catch (\Throwable $exception) {
            Log::warning('[whatsapp] no tenant connection when reading settings', [
                'detail' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function organization(): Organization
    {
        if ($this->organization === null) {
            throw new InvalidArgumentException(
                'Name the organization first: whatsapp()->forOrganization($id)->sendTemplate(...).'
            );
        }

        return $this->organization;
    }
}
