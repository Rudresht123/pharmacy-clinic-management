<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A short-lived Google OAuth access token from the Firebase service account
 * the server already holds for push (config/push.php → FIREBASE_CREDENTIALS).
 *
 * Google's server-to-server flow: a JWT signed RS256 with the account's
 * private key, exchanged for a token good for an hour. Cached per scope for
 * 55 minutes, so a burst of calls signs once.
 */
class ServiceAccountToken
{
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string, project_id?: string}  $credentials
     */
    public function __construct(
        public readonly string $projectId,
        private readonly array $credentials,
    ) {}

    /** The push service account, refusing loudly when it is missing or malformed. */
    public static function fromConfig(): self
    {
        $path = (string) config('push.fcm.credentials');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('The Firebase service account file is missing. Set FIREBASE_CREDENTIALS to its path.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('The Firebase service account file is not a Google service account key.');
        }

        $project = (string) (config('push.fcm.project_id') ?: ($credentials['project_id'] ?? ''));

        if ($project === '') {
            throw new RuntimeException('Firebase needs a project id. Set FIREBASE_PROJECT_ID.');
        }

        return new self($project, $credentials);
    }

    public function for(string $scope): string
    {
        return Cache::remember(
            'google:access-token:'.sha1($this->credentials['client_email'].'|'.$scope),
            now()->addMinutes(55),
            fn () => $this->exchange($scope),
        );
    }

    private function exchange(string $scope): string
    {
        $now = time();
        $tokenUri = $this->credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;

        $unsigned = implode('.', [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $this->credentials['client_email'],
                'scope' => $scope,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ]);

        if (! openssl_sign($unsigned, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google access request. Is the service account key valid?');
        }

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Google refused the access request ({$response->status()}).");
        }

        return $token;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
