<?php

namespace App\Services\Platform;

use App\Models\Platform\PlatformSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Where phones running a development build should find this server.
 *
 * A laptop serving the API on Wi-Fi is given a new address by the router
 * whenever it likes, and every phone pointed at the old one then reports
 * "cannot reach the server" against a perfectly healthy API. Putting the
 * address in code meant editing and rebuilding for every move.
 *
 * Instead it is typed in here, by the platform admin, and published to
 * Firebase Remote Config — which a phone can always reach, because it is on
 * the internet and not on the laptop. The app reads it on launch and tries it
 * before anything else (lib/core/network/api_endpoint.dart in hms_mobile).
 *
 * DEVELOPMENT ONLY. A release build made with its own WORKSPACE_DOMAIN never
 * reads this, so nothing set here can send a shipped app to somebody's laptop.
 */
class MobileServerAddress
{
    /** The Remote Config parameter the app reads. */
    public const REMOTE_KEY = 'dev_api_base_url';

    public function __construct(
        private readonly FirebaseRemoteConfig $remoteConfig,
    ) {}

    /**
     * What the admin screen shows.
     *
     * @return array<string, mixed>
     */
    public function current(?int $port = null): array
    {
        $value = (array) (PlatformSetting::forKey(PlatformSetting::MOBILE_SERVER)->value ?? []);
        $address = $value['address'] ?? null;

        return [
            'address' => $address,
            'api_base' => $address ? $address.$this->apiPrefix() : null,
            'published_at' => $value['published_at'] ?? null,
            'publish_error' => $value['publish_error'] ?? null,
            'suggestions' => $this->suggestions($port),
        ];
    }

    /**
     * Save [$input] and publish it. Blank clears it, and phones go back to
     * finding the server on their own.
     *
     * @return array<string, mixed> the screen's new state
     */
    public function save(?string $input, ?int $port = null): array
    {
        $address = $this->normalise($input);
        $setting = PlatformSetting::forKey(PlatformSetting::MOBILE_SERVER);

        $published = null;
        $error = null;

        try {
            $this->remoteConfig->put(
                self::REMOTE_KEY,
                $address ? $address.$this->apiPrefix() : null,
                'Where development builds of the ZEVRO app find the API. Set from the platform admin (Mobile App).',
            );

            $published = now()->toIso8601String();
        } catch (\Throwable $e) {
            // Saved regardless, so the screen keeps what was typed; the reason
            // it did not reach the phones is shown beside it.
            $error = $e->getMessage();
            Log::warning('Mobile server address not published', ['error' => $error]);
        }

        $setting->value = [
            'address' => $address,
            'published_at' => $published,
            'publish_error' => $error,
        ];
        $setting->save();

        return $this->current($port);
    }

    /**
     * `192.168.1.24:8000`, `http://192.168.1.24:8000/`, `https://abc.ngrok.app`
     * → `http://192.168.1.24:8000` / `https://abc.ngrok.app`. The scheme
     * defaults to http, which is what `php artisan serve` speaks; any path is
     * dropped, since the app adds the API's own.
     */
    public function normalise(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'http://'.$input;
        }

        $parts = parse_url($input);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;

        if (! in_array($scheme, ['http', 'https'], true) || ! $validHost) {
            throw ValidationException::withMessages([
                'address' => 'Enter the address like 192.168.1.24:8000 — this computer\'s IP and the port the API runs on.',
            ]);
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            throw ValidationException::withMessages([
                'address' => 'On a phone, '.$host.' is the phone itself. Use this computer\'s Wi-Fi address instead — one of the suggestions below.',
            ]);
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    /**
     * This computer's own network addresses, ready to use — private ones only,
     * since a phone on the same Wi-Fi is the case this is for.
     *
     * @return list<string>
     */
    private function suggestions(?int $port): array
    {
        $ips = @gethostbynamel((string) gethostname()) ?: [];
        $suffix = $port && $port !== 80 ? ':'.$port : '';

        return array_values(array_unique(array_map(
            fn (string $ip) => 'http://'.$ip.$suffix,
            array_filter($ips, fn (string $ip) => $this->isPrivate($ip)),
        )));
    }

    private function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            && ! str_starts_with($ip, '127.');
    }

    private function apiPrefix(): string
    {
        return '/api/v1';
    }
}
