<?php

namespace App\Services\WhatsApp\Providers;

use App\Services\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use App\Services\WhatsApp\WhatsAppResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Digiware, behind the application's own interface.
 *
 * Everything Digiware-shaped stops here or in the mapper beside it: the base
 * URL, the vendor UID in the path, the token in the query string, the flat
 * `field_n` payload, and whatever its responses happen to look like. Replace
 * this class with a Meta one and no business code changes.
 *
 * The token is never logged, never returned and never put in an exception
 * message. It travels in the query string because that is what the API
 * requires, which is exactly why `safeUrl()` exists — a URL with a token in it
 * must never reach a log line.
 */
class DigiwareWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * Templates and free text are SEPARATE endpoints here, with different
     * required fields — `template_name` + `template_language` on one,
     * `message_body` on the other. Posting a template payload to the text
     * endpoint is a 422, not a delivered message.
     */
    private const TEMPLATE_PATH = 'contact/send-template-message';

    private const TEXT_PATH = 'contact/send-message';

    /**
     * @param  array{base_url?: string, vendor_uid?: string, token?: string, from_phone_number_id?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
        private readonly DigiwarePayloadMapper $mapper = new DigiwarePayloadMapper,
    ) {}

    public function key(): string
    {
        return 'digiware';
    }

    public function sendTemplate(WhatsAppMessage $message): WhatsAppResponse
    {
        if (($missing = $this->missingCredentials()) !== null) {
            return WhatsAppResponse::failed($this->key(), $missing, 'config');
        }

        // The message already carries what this provider calls the template
        // and the order its variables go in — the manager resolved both from
        // the mapping before handing it over.
        $payload = $this->mapper->template(
            $message,
            $message->templateName(),
            $message->order(),
        );

        return $this->post(self::TEMPLATE_PATH, $payload);
    }

    /**
     * Free text, which is its own endpoint here.
     *
     * WhatsApp only delivers this inside an open 24-hour conversation — the
     * provider accepts it regardless and the refusal comes back from WhatsApp,
     * so the failure is reported rather than guessed at. Anything reaching a
     * patient cold has to be a template.
     */
    public function sendText(WhatsAppMessage $message): WhatsAppResponse
    {
        if (($missing = $this->missingCredentials()) !== null) {
            return WhatsAppResponse::failed($this->key(), $missing, 'config');
        }

        return $this->post(self::TEXT_PATH, [
            'phone_number' => $message->normalizedPhone(),
            'message_body' => (string) $message->body,
        ]);
    }

    public function sendMedia(WhatsAppMessage $message): WhatsAppResponse
    {
        return WhatsAppResponse::failed(
            $this->key(),
            'Send media as a template header rather than on its own.',
            'unsupported',
        );
    }

    /**
     * Prove the credentials work, without messaging anybody.
     *
     * This API exposes no read endpoint — every path that is not a send
     * answers 404 with an HTML page — so the check is a DELIBERATELY EMPTY
     * SEND. It carries no phone number, so it cannot reach anybody, and the
     * two outcomes are cleanly distinguishable:
     *
     *   valid token   → 422 {"errors":{"phone_number":["…required"], …}}
     *   invalid token → 200 {"result":"failed","message":"Invalid Token"}
     *
     * Note the second one: a REJECTED TOKEN COMES BACK AS HTTP 200. Anything
     * here that trusted the status code would report a dead account as
     * healthy, which is why the body is what decides.
     */
    public function testConnection(): WhatsAppResponse
    {
        if (($missing = $this->missingCredentials()) !== null) {
            return WhatsAppResponse::failed($this->key(), $missing, 'config');
        }

        try {
            $response = $this->client()->asForm()->post($this->endpoint(self::TEMPLATE_PATH), []);
            $body = $this->decode($response);

            $verdict = strtolower((string) ($body['result'] ?? $body['status'] ?? ''));

            if ($verdict === 'failed' || $verdict === 'error') {
                return WhatsAppResponse::failed(
                    $this->key(),
                    $this->errorMessage($body, $response->status()),
                    'credentials',
                );
            }

            // The payload was empty on purpose, so being told the phone number
            // is missing is the credentials having been accepted.
            if ($response->status() === 422) {
                return WhatsAppResponse::ok($this->key(), 'WhatsApp provider connected successfully.');
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return WhatsAppResponse::failed(
                    $this->key(),
                    'Digiware rejected the API token.',
                    (string) $response->status(),
                );
            }

            if ($response->status() === 404) {
                return WhatsAppResponse::failed(
                    $this->key(),
                    'Digiware did not recognise the vendor UID.',
                    '404',
                );
            }

            if ($response->serverError()) {
                return WhatsAppResponse::transientFailure(
                    $this->key(),
                    'Digiware is not responding. Try again shortly.',
                    (string) $response->status(),
                );
            }

            return WhatsAppResponse::ok($this->key(), 'WhatsApp provider connected successfully.');
        } catch (ConnectionException $exception) {
            return WhatsAppResponse::transientFailure(
                $this->key(),
                'Could not reach Digiware. Check the base URL and the network.',
                'connection',
            );
        } catch (Throwable $exception) {
            return WhatsAppResponse::fromException(
                $this->key(),
                $exception,
                'Unable to connect to the WhatsApp provider.',
            );
        }
    }

    /**
     * One request, and every way it can go wrong.
     *
     * The distinction that matters is transient versus permanent: a timeout
     * or a 5xx is worth another attempt, while "template not found" will say
     * the same thing three times and only delays the failure being noticed.
     *
     * @param  array<string, string>  $payload
     */
    private function post(string $path, array $payload): WhatsAppResponse
    {
        try {
            $response = $this->client()->asForm()->post(
                $this->endpoint($path),
                $payload,
            );
        } catch (ConnectionException $exception) {
            return WhatsAppResponse::transientFailure(
                $this->key(),
                'Could not reach Digiware.',
                'connection',
            );
        } catch (Throwable $exception) {
            return WhatsAppResponse::fromException(
                $this->key(),
                $exception,
                'Unable to send the WhatsApp message.',
            );
        }

        return $this->interpret($response);
    }

    /**
     * Digiware's answer, read defensively.
     *
     * A 200 carrying `status: error` is a real shape from this API, so the
     * body is inspected rather than the code trusted; and a body that is not
     * JSON at all — an HTML error page from a proxy — must be a clean failure
     * rather than an exception thrown while parsing it.
     */
    private function interpret(Response $response): WhatsAppResponse
    {
        $body = $this->decode($response);

        if ($response->serverError()) {
            return WhatsAppResponse::transientFailure(
                $this->key(),
                'Digiware is not responding.',
                (string) $response->status(),
                $body,
            );
        }

        if ($response->status() === 429) {
            return WhatsAppResponse::transientFailure(
                $this->key(),
                'Digiware is rate limiting this account.',
                '429',
                $body,
            );
        }

        /*
         * The verdict is under `result`, confirmed against a real send:
         *
         *   {"result":"success","message":"Message processed",
         *    "data":{"wamid":"wamid.HBgM…","status":"accepted", …}}
         *
         * `status` is read too, and is checked for a NEGATIVE word rather than
         * a positive one. A 200 whose body says nothing either way is a
         * success, but a 200 that says "error" is not — and reading only
         * `status` here would have called every future failure a send, because
         * this API does not put its verdict there.
         */
        $verdict = strtolower((string) ($body['result'] ?? $body['status'] ?? ''));
        $refused = in_array($verdict, ['error', 'failed', 'failure', 'false'], true);

        $succeeded = $response->successful() && ! $refused;

        if (! $succeeded) {
            return WhatsAppResponse::failed(
                $this->key(),
                $this->errorMessage($body, $response->status()),
                (string) ($body['code'] ?? $response->status()),
                $body,
            );
        }

        return WhatsAppResponse::sent(
            $this->key(),
            $this->messageId($body),
            'Message accepted by Digiware',
            $body,
        );
    }

    /**
     * The provider's id for this message.
     *
     * Digiware returns it as `data.wamid`, confirmed against a real send. The
     * other keys are kept as fallbacks: the shape is not contractual, and a
     * missing id must not fail the send — it only means delivery receipts
     * cannot be matched later, which is worth less than the message.
     *
     * `log_uid` is deliberately LAST. It is Digiware's own record id, not
     * WhatsApp's, so a webhook carrying a wamid would never match it — it is a
     * better answer than nothing and a worse one than the real id.
     *
     * @param  array<string, mixed>  $body
     */
    private function messageId(array $body): ?string
    {
        $keys = ['wamid', 'message_id', 'messageId', 'id', 'wam_id', 'log_uid'];

        foreach ([$body, $body['data'] ?? null] as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($keys as $key) {
                if (isset($source[$key]) && is_scalar($source[$key])) {
                    return (string) $source[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body, int $status): string
    {
        foreach (['message', 'error', 'description'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return "Digiware refused the message (HTTP {$status}).";
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return ['body' => 'unreadable'];
        }

        return is_array($body) ? $body : ['body' => (string) $response->body()];
    }

    private function client()
    {
        $http = config('whatsapp.http');

        return Http::timeout($http['timeout'])
            ->connectTimeout($http['connect_timeout'])
            // Connection-level faults only. An HTTP error is an answer, and
            // retrying an answer is how one bad template becomes three.
            ->retry($http['retries'], $http['retry_delay_ms'], throw: false)
            ->acceptJson();
    }

    /**
     * The full URL, token and all.
     *
     * Never logged. Laravel's HTTP client records the request URI when
     * exceptions are dumped, which is precisely why `post()` catches
     * everything and returns its own message rather than letting one bubble.
     */
    private function endpoint(string $path): string
    {
        $base = rtrim((string) $this->credentials['base_url'], '/');
        $vendor = rawurlencode((string) $this->credentials['vendor_uid']);
        $token = rawurlencode((string) $this->credentials['token']);

        return "{$base}/{$vendor}/{$path}?token={$token}";
    }

    /** What is safe to write down about where a request went. */
    public function safeUrl(string $path): string
    {
        $base = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');

        return "{$base}/{vendor}/{$path}?token=[redacted]";
    }

    /** The first thing missing, phrased for whoever has to fix it. */
    private function missingCredentials(): ?string
    {
        foreach (['base_url' => 'API base URL', 'vendor_uid' => 'vendor UID', 'token' => 'API token'] as $key => $label) {
            if (empty($this->credentials[$key])) {
                return "Digiware is missing its {$label}.";
            }
        }

        return null;
    }
}
