<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\PlatformSetting;
use App\Services\Email\EmailManager;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The accounts every organization's messages go out through.
 *
 * PLATFORM-LEVEL, not per clinic. There is one commercial relationship with
 * each provider — one WhatsApp Business account, one mail relay — so a clinic
 * setting these would be changing what every OTHER clinic sends through.
 *
 * One controller for both channels because the job is identical: pick a
 * provider, store its credentials encrypted, prove they work. The difference
 * between them is a config file, not code.
 */
class MessagingSettingsController extends BaseApiController
{
    /**
     * Where each channel's options and stored settings live.
     *
     * @return array{config: string, key: string}
     */
    private function channel(string $channel): array
    {
        return match ($channel) {
            'whatsapp' => ['config' => 'whatsapp', 'key' => PlatformSetting::WHATSAPP],
            'email' => ['config' => 'email', 'key' => PlatformSetting::EMAIL],
            default => abort(404),
        };
    }

    /**
     * What is configured, and what is still missing.
     *
     * Returns the SHAPE of the credentials and never their contents. A secret
     * comes back as `is_set: true` and nothing more — an administrator needs
     * to know whether a token is present, not what it is, and a secret that
     * reaches the browser is a secret in somebody's devtools and browser cache.
     */
    public function show(string $channel): JsonResponse
    {
        ['config' => $config, 'key' => $key] = $this->channel($channel);

        $setting = PlatformSetting::forKey($key);
        $stored = $setting->secret ?? [];
        $current = $setting->value['provider_key'] ?? config("{$config}.default");

        $options = collect(config("{$config}.providers"))
            ->map(fn (array $provider, string $providerKey) => [
                'key' => $providerKey,
                'label' => $provider['label'] ?? $providerKey,

                'fields' => collect($provider['fields'] ?? [])
                    ->map(fn (array $field, string $name) => [
                        'name' => $name,
                        'label' => $field['label'] ?? $name,
                        'required' => (bool) ($field['required'] ?? false),
                        'secret' => (bool) ($field['secret'] ?? false),
                        'placeholder' => $field['placeholder'] ?? null,
                        'hint' => $field['hint'] ?? null,

                        // A fixed set of choices renders as a dropdown; the
                        // form should not have to know which fields those are.
                        'options' => isset($field['options'])
                            ? collect($field['options'])
                                ->map(fn (string $label, string $value) => [
                                    'value' => $value,
                                    'label' => $label,
                                ])
                                ->values()
                            : null,

                        // A secret says only whether it is there. A host name
                        // is not a credential, and re-typing one is how it
                        // gets typo'd.
                        'value' => ($field['secret'] ?? false) ? null : ($stored[$name] ?? null),

                        /*
                         * Set by config counts as set. Otherwise a deployment
                         * whose credentials come from .env would be shown as
                         * unconfigured while sending perfectly well.
                         */
                        'is_set' => filled($stored[$name] ?? null)
                            || filled($this->fromConfig($config, $providerKey, $name)),

                        'from_env' => blank($stored[$name] ?? null)
                            && filled($this->fromConfig($config, $providerKey, $name)),
                    ])
                    ->values(),
            ])
            ->values();

        return $this->ok([
            'channel' => $channel,
            'current' => $current,
            'options' => $options,
            'verified_at' => $setting->value['verified_at'] ?? null,
            'verification_error' => $setting->value['verification_error'] ?? null,
        ]);
    }

    /**
     * Save the provider and its credentials.
     *
     * A blank secret MEANS "leave it alone". Without that rule, opening this
     * screen to correct a host name and saving would wipe the password, and
     * every clinic on the platform would stop sending at once.
     */
    public function update(Request $request, string $channel): JsonResponse
    {
        ['config' => $config, 'key' => $key] = $this->channel($channel);

        $validated = $request->validate([
            'provider_key' => [
                'required',
                'string',
                Rule::in(array_keys(config("{$config}.providers"))),
            ],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ], [
            'provider_key.in' => 'That provider is not one this system can send through.',
        ]);

        $setting = PlatformSetting::forKey($key);
        $fields = config("{$config}.providers.{$validated['provider_key']}.fields", []);

        $existing = $setting->secret ?? [];
        $incoming = $validated['credentials'] ?? [];
        $credentials = [];

        foreach ($fields as $name => $field) {
            $supplied = trim((string) ($incoming[$name] ?? ''));

            $credentials[$name] = match (true) {
                $supplied !== '' => $supplied,
                ($field['secret'] ?? false) => $existing[$name] ?? null,

                // A non-secret cleared on purpose should clear, so it can fall
                // back to the config value by emptying the box.
                default => null,
            };
        }

        $setting->key = $key;
        $setting->secret = array_filter($credentials, static fn ($value) => $value !== null);

        // The old result describes the old credentials. Leaving it would show
        // a green tick against a password just replaced.
        $setting->value = [
            'provider_key' => $validated['provider_key'],
            'verified_at' => null,
            'verification_error' => null,
        ];

        $setting->save();

        return $this->ok(null, 'Saved. Test the connection to confirm it works.');
    }

    /**
     * Whether the platform's credentials actually work.
     *
     * Answers with the normalized result and NOTHING ELSE. A provider's own
     * error text is for the server log: a Digiware URL carries its token in
     * the query string, and an SMTP failure quotes the transport DSN, which
     * carries the password.
     */
    public function test(string $channel): JsonResponse
    {
        ['key' => $key] = $this->channel($channel);

        [$success, $message] = $channel === 'email'
            ? (function () {
                $result = app(EmailManager::class)->testConnection();

                return [$result->success, $result->message];
            })()
            : (function () {
                $result = app(WhatsAppManager::class)->testConnection();

                return [$result->success, $result->message];
            })();

        $setting = PlatformSetting::forKey($key);
        $setting->key = $key;

        $setting->value = array_merge($setting->value ?? [], [
            'verified_at' => $success ? now()->toIso8601String() : null,
            'verification_error' => $success ? null : mb_substr($message, 0, 500),
        ]);

        $setting->save();

        return $this->ok(['success' => $success], $message);
    }

    /** The deployment's own value for one credential, if it has one. */
    private function fromConfig(string $config, string $provider, string $name): mixed
    {
        return config("{$config}.providers.{$provider}.credentials.{$name}")
            ?? config("{$config}.credentials.{$name}");
    }
}
