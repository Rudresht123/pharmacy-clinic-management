<?php

namespace App\Services\Platform;

use App\Services\Google\ServiceAccountToken;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes one string parameter into the Firebase project's Remote Config.
 *
 * Remote Config is the one place every phone can read whatever network it is
 * on — it is Google's, on the internet, at an address that never changes —
 * which is what makes it the right home for a value that says where the
 * server is when the server's own address is the thing that moved.
 *
 * The template is read, the one parameter changed, and the whole template
 * written back under its ETag, so a parameter somebody else added in the
 * Firebase console survives, and two saves at once cannot silently undo each
 * other (the second is refused and says so).
 */
class FirebaseRemoteConfig
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.remoteconfig';

    public function __construct(
        private readonly ?ServiceAccountToken $account = null,
    ) {}

    /** Sets [$key] to [$value], or removes it when [$value] is null. */
    public function put(string $key, ?string $value, string $description): void
    {
        $account = $this->account ?? ServiceAccountToken::fromConfig();
        $url = "https://firebaseremoteconfig.googleapis.com/v1/projects/{$account->projectId}/remoteConfig";
        $token = $account->for(self::SCOPE);

        // The ETag only comes back to a client that accepts gzip.
        $current = Http::withToken($token)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->timeout(10)
            ->get($url);

        if (! $current->successful()) {
            throw new RuntimeException('Firebase did not hand over the Remote Config ('.$this->reason($current).').');
        }

        $template = (array) $current->json();
        $parameters = (array) ($template['parameters'] ?? []);

        if ($value === null) {
            unset($parameters[$key]);
        } else {
            $parameters[$key] = [
                'defaultValue' => ['value' => $value],
                'description' => $description,
                'valueType' => 'STRING',
            ];
        }

        // An object even when empty: Remote Config refuses `"parameters": []`.
        $template['parameters'] = (object) $parameters;
        unset($template['version']);

        $saved = Http::withToken($token)
            ->withHeaders([
                'If-Match' => $current->header('ETag') ?: '*',
                'Content-Type' => 'application/json; UTF-8',
            ])
            ->timeout(10)
            ->withBody((string) json_encode($template), 'application/json; UTF-8')
            ->put($url);

        if (! $saved->successful()) {
            throw new RuntimeException('Firebase did not accept the change ('.$this->reason($saved).').');
        }
    }

    private function reason(\Illuminate\Http\Client\Response $response): string
    {
        $message = (string) $response->json('error.message', '');

        return $message !== '' ? "{$response->status()}: {$message}" : (string) $response->status();
    }
}
