<?php

namespace App\Services\Push;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, over the HTTP v1 API, with no SDK.
 *
 * Two calls, both plain HTTPS:
 *
 *   1. Exchange a JWT signed with the service account's private key for a
 *      short-lived OAuth access token (Google's documented server-to-server
 *      flow). Cached for 55 of its 60 minutes, so a busy morning signs one.
 *   2. POST one message per device token. v1 has no multicast; the tokens go
 *      out concurrently through Http::pool, so ten devices cost one round
 *      trip of wall time, not ten.
 *
 * Every token's answer is sorted into PushResult — and only the answers that
 * genuinely mean "this device is gone" switch a device off. A malformed
 * message comes back INVALID_ARGUMENT for every token it was sent to; treating
 * that as "invalid device" would switch off the whole clinic's phones.
 *
 * The service account JSON is read from a path (FIREBASE_CREDENTIALS), never
 * stored in the database or the repository.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** FCM's own word that the device will never receive from us again. */
    private const GONE = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    /** Busy or down — worth trying again. */
    private const TRANSIENT = ['UNAVAILABLE', 'INTERNAL', 'QUOTA_EXCEEDED'];

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    public function __construct(
        private readonly string $projectId,
        private readonly array $credentials,
    ) {}

    /** Built from config/push.php, refusing loudly when it is incomplete. */
    public static function fromConfig(): self
    {
        $path = (string) config('push.fcm.credentials');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('FCM is selected but its service account file is missing. Set FIREBASE_CREDENTIALS to its path.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('The FCM service account file is not a Google service account key.');
        }

        $project = (string) (config('push.fcm.project_id') ?: ($credentials['project_id'] ?? ''));

        if ($project === '') {
            throw new RuntimeException('FCM needs a project id. Set FIREBASE_PROJECT_ID.');
        }

        return new self($project, $credentials);
    }

    public function send(PushMessage $message, array $tokens): PushResult
    {
        if ($tokens === []) {
            return new PushResult;
        }

        $access = $this->accessToken();
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $token) => $pool->as($token)
                ->withToken($access)
                ->acceptJson()
                ->timeout(10)
                ->post($url, $this->payload($message, $token)),
            $tokens,
        ));

        $sorted = ['sent' => [], 'invalid' => [], 'retry' => [], 'failed' => []];

        foreach ($tokens as $token) {
            $sorted[$this->outcome($responses[$token] ?? null)][] = $token;
        }

        return new PushResult($sorted['sent'], $sorted['invalid'], $sorted['retry'], $sorted['failed']);
    }

    /** @return array<string, mixed> */
    private function payload(PushMessage $message, string $token): array
    {
        return [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $message->title, 'body' => $message->body],
                // An object even when empty: FCM refuses `"data": []`.
                'data' => (object) $message->data,
                'android' => ['priority' => 'HIGH'],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];
    }

    /** sent | invalid | retry | failed — see PushResult. */
    private function outcome(mixed $response): string
    {
        // A connection that never answered is the network's fault, not the device's.
        if (! $response instanceof Response) {
            return 'retry';
        }

        if ($response->successful()) {
            return 'sent';
        }

        $code = collect((array) $response->json('error.details', []))
            ->pluck('errorCode')
            ->filter()
            ->first();

        if (in_array($code, self::GONE, true) || $response->status() === 404) {
            return 'invalid';
        }

        if (in_array($code, self::TRANSIENT, true) || $response->status() === 429 || $response->serverError()) {
            return 'retry';
        }

        return 'failed';
    }

    private function accessToken(): string
    {
        return Cache::remember(
            'push:fcm:access-token:'.sha1($this->credentials['client_email']),
            now()->addMinutes(55),
            fn () => $this->exchange(),
        );
    }

    /**
     * Google's server-to-server OAuth flow: a JWT signed RS256 with the
     * service account's key, exchanged for an access token.
     */
    private function exchange(): string
    {
        $now = time();
        $tokenUri = $this->credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;

        $unsigned = implode('.', [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $this->credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ]);

        if (! openssl_sign($unsigned, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the FCM access request. Is the service account key valid?');
        }

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Google refused the FCM access request ({$response->status()}).");
        }

        return $token;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
