<?php

namespace App\Services\Email;

use App\Jobs\SendEmailMessage;
use App\Models\Platform\Organization;
use App\Models\Platform\PlatformSetting;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The one door into email.
 *
 * Deliberately NOT built on a provider interface, unlike WhatsApp. Laravel
 * already abstracts SMTP, SES, Postmark, Mailgun and Resend behind one mailer,
 * so a second interface here would forward every call to an abstraction that
 * already exists. What this class adds is the part Laravel does not have: a
 * mailer built from THIS CLINIC's settings, and a delivery log.
 *
 * QUEUED BY DEFAULT, for the same reason WhatsApp is. An appointment must not
 * wait on somebody else's mail server.
 *
 * The organization must be NAMED rather than assumed. A queue worker serves
 * many tenants in one process, and ambient "current organization" state is how
 * a clinic's mail goes out from another clinic's address.
 */
class EmailManager
{
    private ?Organization $organization = null;

    /** @var array<string, mixed>|null */
    private ?array $platformSettings = null;

    /**
     * Returns a CLONE rather than mutating: the manager is resolved from the
     * container and two callers in one request must not be able to change each
     * other's target.
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
     * Queue one email, and answer with the log row that will carry it.
     *
     * The row is written HERE, at status queued, before the job runs — so a
     * message exists in the log from the moment it was asked for. A queue that
     * never drains then leaves evidence rather than silence.
     *
     * @param  array<string, mixed>  $extra  columns the caller owns, written in
     *                                       the SAME insert because the worker
     *                                       may reach the row first
     */
    public function queue(EmailMessage $message, ?int $customerId = null, array $extra = []): MessageLog
    {
        $organization = $this->organization();

        $log = MessageLog::query()->create($extra + [
            'channel' => CommunicationChannel::EMAIL,
            'provider' => 'smtp',
            'customer_id' => $customerId,
            'recipient' => $message->to,
            'template_name' => $message->template,

            // The template's subject, UNFILLED. The sent subject has the
            // patient's name in it, and a delivery log support staff can read
            // is not the place for it.
            'subject' => $message->subject,
            'status' => MessageLog::QUEUED,
            'scheduled_for' => Carbon::now(),
            'request_payload' => $message->toLogContext(),
        ]);

        $queue = config('email.queue');

        // The values ride on the job body, whose row the queue deletes the
        // moment it finishes, rather than living forever in a delivery log.
        SendEmailMessage::dispatch($organization->getKey(), $log->id, [
            'to' => $message->to,
            'subject' => $message->subject,
            'body' => $message->body,
            'template' => $message->template,
            'variables' => $message->variables,
        ])
            ->onConnection($queue['connection'])
            ->onQueue($queue['queue']);

        return $log;
    }

    /**
     * Send now and write the outcome onto the log row.
     *
     * For the paths that need an answer: a test somebody is watching for, and
     * the job, which has already waited its turn.
     */
    public function sendNow(EmailMessage $message, MessageLog $log): EmailResult
    {
        $settings = $this->settings();
        $result = $this->deliver($message, $settings);

        return $this->record($log, $result);
    }

    /**
     * Hand it to the mailer, and turn every way that can fail into an answer.
     *
     * A transport exception is the normal failure here — a refused login, a
     * rejected recipient, a relay that is down — and it must not escape as an
     * exception, because the caller's job is to record it, not to crash.
     */
    private function deliver(EmailMessage $message, array $settings): EmailResult
    {
        if (($missing = $this->missing($settings)) !== null) {
            return EmailResult::failed($missing, 'config');
        }

        $from = $this->from($settings);

        try {
            $this->mailer($settings)->html(
                $message->rendered(),
                function ($mail) use ($message, $settings, $from) {
                    $mail->to($message->to)
                        ->subject($message->renderedSubject())
                        ->from($from['address'], $from['name']);

                    if (filled($settings['reply_to'] ?? null)) {
                        $mail->replyTo($settings['reply_to']);
                    }
                },
            );

            return EmailResult::sent();
        } catch (TransportExceptionInterface $exception) {
            /*
             * The relay itself failed. Worth retrying: a mail server that is
             * refusing connections at nine o'clock is usually accepting them
             * at ten, unlike a rejected password, which will be rejected again.
             */
            Log::warning('[email] transport failure', [
                'organization_id' => $this->organization?->getKey(),
                'detail' => $exception->getMessage(),
            ]);

            return EmailResult::transient($this->readable($exception->getMessage()));
        } catch (Throwable $exception) {
            Log::error('[email] send failed', [
                'organization_id' => $this->organization?->getKey(),
                'detail' => $exception->getMessage(),
            ]);

            return EmailResult::failed($this->readable($exception->getMessage()), 'send');
        }
    }

    /**
     * Whether the clinic's SMTP settings actually work.
     *
     * Opens the transport and stops. Deliberately NOT a send: somebody will
     * press this button twenty times while getting their settings right, and a
     * "test" that mails a real address twenty times is not a test.
     */
    public function testConnection(): EmailResult
    {
        $settings = $this->settings();

        if (($missing = $this->missing($settings)) !== null) {
            return EmailResult::failed($missing, 'config');
        }

        if (! $this->configured($settings)) {
            // Saying "connected successfully" here would be a green tick for a
            // clinic that has configured nothing and whose mail is going to a
            // log file.
            return EmailResult::failed(
                'No mail server configured. Mail is going through the application default.',
                'config',
            );
        }

        try {
            $transport = $this->mailer($settings)->getSymfonyTransport();

            // Starting the transport is the whole check: it resolves the host,
            // negotiates TLS and authenticates, which is every way a setting
            // can be wrong, without a message existing.
            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }

            return EmailResult::ok('Mail server connected successfully.');
        } catch (TransportExceptionInterface $exception) {
            return EmailResult::failed($this->readable($exception->getMessage()), 'credentials');
        } catch (Throwable $exception) {
            return EmailResult::failed($this->readable($exception->getMessage()), 'connection');
        }
    }

    /**
     * A mailer built from this clinic's settings.
     *
     * `Mail::build()` rather than writing into `config('mail.mailers')`: the
     * config is process-wide and a queue worker serves many tenants, so a
     * mailer registered under a shared name is one tenant's credentials
     * waiting to be used by the next tenant's job.
     *
     * @param  array<string, mixed>  $settings
     */
    private function mailer(array $settings): Mailer
    {
        if (! $this->configured($settings)) {
            return Mail::mailer(config('email.fallback_mailer'));
        }

        return Mail::build([
            'transport' => 'smtp',
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            'encryption' => $settings['encryption'] ?: null,
            'username' => $settings['username'],
            'password' => $settings['password'],
            'timeout' => 15,
        ]);
    }

    /**
     * Whether this clinic has a mail server of its own.
     *
     * The host is the tell. Everything else is meaningless without it, and a
     * clinic that has filled in nothing is a clinic that has not got to this
     * screen yet — not a misconfigured one.
     *
     * @param  array<string, mixed>  $settings
     */
    private function configured(array $settings): bool
    {
        return filled($settings['host'] ?? null);
    }

    /**
     * Who the mail is from.
     *
     * The clinic's own address when it has one, the application's otherwise.
     * A message with no from-address is rejected by every relay, so falling
     * back here is what lets a development box send at all.
     *
     * @param  array<string, mixed>  $settings
     * @return array{address: string, name: ?string}
     */
    private function from(array $settings): array
    {
        return [
            'address' => $settings['from_address'] ?: (string) config('mail.from.address'),
            'name' => $settings['from_name'] ?: config('mail.from.name'),
        ];
    }

    /**
     * This clinic's settings, or nothing.
     *
     * No fallback to another clinic's, ever. Mail that goes out from the wrong
     * address is worse than mail that does not go out, because the first is
     * discovered by a patient and the second by a queue.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        try {
            $stored = CommunicationChannel::forChannel(CommunicationChannel::EMAIL)->credentials ?? [];
        } catch (Throwable $exception) {
            // No tenant connection — a platform-level connection test, say.
            // The relay still resolves, so this degrades rather than fails.
            Log::warning('[email] no tenant connection when reading settings', [
                'detail' => $exception->getMessage(),
            ]);

            $stored = [];
        }

        return array_merge(
            array_fill_keys(array_keys($this->fields()), null),
            $this->present($this->platform()),
            $this->present($stored),
        );
    }

    /**
     * The platform's mail relay, read once per manager.
     *
     * The SUPERADMIN's, and normally the real one: there is one relay account
     * for the whole platform. A clinic that brings its own mail server still
     * overrides this, which is the layer above.
     *
     * Memoized because this sits on the path of every send, and a campaign to
     * two thousand patients must not be two thousand reads of one row.
     *
     * @return array<string, mixed>
     */
    private function platform(): array
    {
        if ($this->platformSettings !== null) {
            return $this->platformSettings;
        }

        try {
            return $this->platformSettings = PlatformSetting::forKey(PlatformSetting::EMAIL)->secret ?? [];
        } catch (Throwable $exception) {
            Log::warning('[email] could not read platform settings', [
                'detail' => $exception->getMessage(),
            ]);

            return $this->platformSettings = [];
        }
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

    /** @return array<string, array<string, mixed>> */
    private function fields(): array
    {
        return config('email.providers.'.config('email.default').'.fields', []);
    }

    /**
     * The first required setting that is not there.
     *
     * Named rather than counted, because "SMTP is not configured" sends
     * somebody to read all seven boxes to find the empty one.
     *
     * @param  array<string, mixed>  $settings
     */
    private function missing(array $settings): ?string
    {
        // Nothing filled in at all is not a misconfiguration — it is a clinic
        // that has not reached this screen, and it falls back to the
        // application's own mailer rather than being refused.
        if (! $this->configured($settings)) {
            return null;
        }

        foreach ($this->fields() as $name => $field) {
            if (($field['required'] ?? false) && blank($settings[$name] ?? null)) {
                return "Email is not configured: {$field['label']} is missing.";
            }
        }

        return null;
    }

    /**
     * A mail server's complaint, trimmed to something a person can act on.
     *
     * The raw message quotes the transport DSN, which carries the password.
     */
    private function readable(string $message): string
    {
        $secrets = config('email.redact', []);

        $message = preg_replace('/\b(' . implode('|', $secrets) . ')=\S+/i', '$1=[redacted]', $message) ?? $message;

        // smtp://user:pass@host — the password sits between the colon and the at.
        $message = preg_replace('#(smtps?://[^:/@]+):[^@]*@#i', '$1:[redacted]@', $message) ?? $message;

        return mb_substr(trim($message), 0, 300);
    }

    /** One place, so every path leaves the same shape behind. */
    public function record(MessageLog $log, EmailResult $result): EmailResult
    {
        $now = Carbon::now();

        $log->forceFill([
            'provider' => 'smtp',
            'status' => $result->status,
            'error_code' => $result->errorCode,
            'failure_reason' => $result->success ? null : mb_substr($result->message, 0, 500),
            'attempts' => $log->attempts + 1,
            'sent_at' => $result->success ? ($log->sent_at ?? $now) : $log->sent_at,
            'failed_at' => $result->success ? null : $now,
        ])->save();

        return $result;
    }

    private function organization(): Organization
    {
        if ($this->organization === null) {
            throw new InvalidArgumentException(
                'Name the organization first: emailer($id)->queue(...).'
            );
        }

        return $this->organization;
    }
}
