<?php

namespace App\Notifications\Tenant;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A tenant staff member's reset link.
 *
 * Not Laravel's stock `ResetPassword` notification: that one builds its URL
 * through `ResetPassword::createUrlUsing`, which AppServiceProvider points at
 * the single APP_URL — fine for the platform panel, which lives at one
 * address, but a tenant is reached at `{subdomain}.{main_domain}` and the
 * link has to land back on the right one. `subdomain` travels in the query
 * string too, the same fallback LoginRequest's own field is, for whenever
 * the Host header alone can't say which tenant this is.
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $subdomain,
        private readonly string $scheme,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** The plaintext token — read back in tests, which can't recover it from the hashed row. */
    public function token(): string
    {
        return $this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }

    private function resetUrl(object $notifiable): string
    {
        $domain = config('organization.main_domain');
        $query = http_build_query([
            'email' => $notifiable->getEmailForPasswordReset(),
            'subdomain' => $this->subdomain,
        ]);

        if (! $domain) {
            return url('/reset-password/'.$this->token.'?'.$query);
        }

        // Same reasoning as OrganizationSetupController::tenantLoginUrl: the
        // scheme follows whatever the request that asked for this arrived
        // on, not a hardcoded https:// — local development serves these
        // hosts over plain http, where an https link simply fails to connect.
        return $this->scheme.'://'.$this->subdomain.'.'.$domain.'/reset-password/'.$this->token.'?'.$query;
    }
}
