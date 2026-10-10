<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\MessageTemplateVariant;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Modules\FeatureResolver;
use App\Services\Permissions\Permission;
use App\Services\Push\PushManager;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppMessage;
use App\Support\Communication\MessageVariables;

/**
 * One templated message to one patient, on one channel — the switches and the
 * routing, in one place.
 *
 * Automation rules (AutomationSender) and campaigns (CampaignDispatcher) both
 * send through here, so the two can never disagree about when a message may
 * go: the communication module running, the channel connected, the template
 * active and approved, the patient reachable there.
 *
 * GLOBAL → ORGANISATION → BRANCH. The template is the organisation's (it began
 * as the platform's default for App notifications). At a branch with its own
 * active wording (message_template_variants) that wording is sent instead.
 * WhatsApp has none — its wording is what the provider approved.
 *
 * Delivery is each channel's own manager — EmailManager, WhatsAppManager,
 * PushManager — so every message lands in the same delivery log and retries
 * the same way, from the clinic's own account.
 */
class ChannelSender
{
    /** The channels something can actually be handed to today. SMS has no provider. */
    public const DELIVERABLE = [
        CommunicationChannel::EMAIL,
        CommunicationChannel::WHATSAPP,
        CommunicationChannel::PUSH,
    ];

    public function __construct(
        private readonly Permission $permission,
        private readonly FeatureResolver $features,
        private readonly EmailManager $email,
        private readonly WhatsAppManager $whatsapp,
        private readonly PushManager $push,
    ) {}

    /**
     * Whether the communication module runs at this branch (null:
     * organisation-wide), and which channels are connected.
     *
     * @return array{enabled: bool, channels: array<string, bool>}
     */
    public function communication(Organization $organization, ?int $locationId): array
    {
        return $this->features->communicationFor($this->permission->modulesAt($organization, $locationId));
    }

    /** Active, approved, and written for this channel. A draft never goes out. */
    public static function sendable(?MessageTemplate $template, string $channel): bool
    {
        return $template !== null
            && $template->deleted_at === null
            && $template->is_active
            && $template->status === MessageTemplate::APPROVED
            && $template->channel === $channel;
    }

    /**
     * Queue it, and answer with the delivery-log row. Null when this patient
     * cannot be reached on this channel, or the template may not be sent.
     *
     * The caller has checked the module and the connection (communication());
     * this checks the template and the address.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $extra  log columns the caller owns (campaign_id…)
     * @param  array<string, scalar|null>  $data  push references (type, ids)
     */
    public function queue(
        Organization $organization,
        string $channel,
        Customer $customer,
        MessageTemplate $template,
        array $variables,
        ?int $locationId = null,
        array $extra = [],
        array $data = [],
        ?string $subject = null,
    ): ?MessageLog {
        if (! in_array($channel, self::DELIVERABLE, true) || ! self::sendable($template, $channel)) {
            return null;
        }

        [$templateSubject, $content] = $this->wording($template, $locationId);

        return $this->deliver(
            $organization,
            $channel,
            $customer,
            filled($subject) ? $subject : $templateSubject,
            $content,
            $variables,
            $extra + ['message_template_id' => $template->id],
            $data,
            $template->name,
        );
    }

    /**
     * Wording written on the message itself — a campaign's own subject and
     * body rather than a template's. Email and App notifications only: a
     * WhatsApp message may only ever be a template the provider approved.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $extra
     * @param  array<string, scalar|null>  $data
     */
    public function queueWording(
        Organization $organization,
        string $channel,
        Customer $customer,
        ?string $subject,
        string $content,
        array $variables,
        string $label,
        array $extra = [],
        array $data = [],
    ): ?MessageLog {
        if (! in_array($channel, CommunicationChannel::REWORDABLE, true) || blank($content)) {
            return null;
        }

        return $this->deliver($organization, $channel, $customer, $subject, $content, $variables, $extra, $data, $label);
    }

    /**
     * Hand it to the channel's own manager.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $extra
     * @param  array<string, scalar|null>  $data
     */
    private function deliver(
        Organization $organization,
        string $channel,
        Customer $customer,
        ?string $subject,
        string $content,
        array $variables,
        array $extra,
        array $data,
        string $name,
    ): ?MessageLog {
        $extra += ['recipient_name' => $customer->name];

        return match ($channel) {
            CommunicationChannel::EMAIL => blank($customer->email) ? null : $this->email
                ->forOrganization($organization)
                ->queue(
                    EmailMessage::make(
                        to: (string) $customer->email,
                        subject: (string) ($subject ?: $name),
                        body: $content,
                        template: $name,
                        variables: $variables,
                    ),
                    $customer->id,
                    $extra,
                ),

            CommunicationChannel::WHATSAPP => blank($customer->phone) ? null : $this->whatsapp
                ->forOrganization($organization)
                ->queue(
                    WhatsAppMessage::template(
                        phone: (string) $customer->phone,
                        template: $name,
                        variables: $variables,
                    ),
                    $customer->id,
                    $extra,
                ),

            CommunicationChannel::PUSH => $this->push->queue(
                $organization,
                $customer,
                MessageVariables::fill($subject ?: $name, $variables),
                MessageVariables::fill($content, $variables),
                $data,
                // The log keeps the wording UNFILLED, as email does.
                $extra + ['subject' => $subject, 'template_name' => $name],
            ),

            default => null,
        };
    }

    /**
     * What this template says at this branch: the branch's own active wording
     * when it has one, the organisation's otherwise.
     *
     * @return array{0: string|null, 1: string}
     */
    public function wording(MessageTemplate $template, ?int $locationId): array
    {
        if ($locationId !== null && in_array($template->channel, CommunicationChannel::REWORDABLE, true)) {
            $variant = MessageTemplateVariant::query()
                ->where('message_template_id', $template->id)
                ->where('location_id', $locationId)
                ->where('is_active', true)
                ->first();

            if ($variant !== null) {
                return [$variant->subject ?: $template->subject, (string) $variant->content];
            }
        }

        return [$template->subject, (string) $template->content];
    }
}
