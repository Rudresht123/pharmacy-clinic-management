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
     * Queue one rule's message to everybody it names (RecipientResolver),
     * and answer with the delivery-log rows.
     *
     * Email is ONE message carrying To, CC and BCC — the copies are copies,
     * as in any mail client — filed against the patient when they are on it.
     * An App notification is one to the patient, logged against them, and one
     * to the staff named. WhatsApp goes to the patient's own number only.
     *
     * The caller has checked whatever this rule's audience needs checking
     * (AutomationSender::send); this checks the template.
     *
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $extra
     * @param  array<string, scalar|null>  $data
     * @param  array<string, scalar|null>  $staffData  what staff phones are given instead, when it differs
     * @return list<MessageLog>
     */
    public function queueTo(
        Organization $organization,
        string $channel,
        Recipients $recipients,
        ?Customer $customer,
        MessageTemplate $template,
        array $variables,
        ?int $locationId = null,
        array $extra = [],
        array $data = [],
        array $staffData = [],
    ): array {
        if (! in_array($channel, self::DELIVERABLE, true) || ! self::sendable($template, $channel) || $recipients->isEmpty()) {
            return [];
        }

        if ($channel === CommunicationChannel::WHATSAPP) {
            $log = $recipients->patient && $customer !== null
                ? $this->queue($organization, $channel, $customer, $template, $variables, $locationId, $extra, $data)
                : null;

            return $log === null ? [] : [$log];
        }

        [$subject, $content] = $this->wording($template, $locationId);
        $extra += ['message_template_id' => $template->id];
        $logs = [];

        if ($channel === CommunicationChannel::EMAIL) {
            foreach ($recipients->envelopes() as $envelope) {
                $to = array_keys($envelope['to']);
                $everybody = [...$to, ...array_keys($envelope['cc']), ...array_keys($envelope['bcc'])];
                $withPatient = $recipients->patientAddress !== null && in_array($recipients->patientAddress, $everybody, true);

                $logs[] = $this->email->forOrganization($organization)->queue(
                    EmailMessage::make(
                        to: $to[0],
                        subject: (string) ($subject ?: $template->name),
                        body: $content,
                        template: $template->name,
                        variables: $variables,
                        alsoTo: array_slice($to, 1),
                        cc: array_keys($envelope['cc']),
                        bcc: array_keys($envelope['bcc']),
                    ),
                    $withPatient ? $customer?->id : null,
                    $extra + ['recipient_name' => mb_substr((string) reset($envelope['to']), 0, 150)],
                );
            }

            return $logs;
        }

        $title = MessageVariables::fill($subject ?: $template->name, $variables);
        $body = MessageVariables::fill($content, $variables);

        // The log keeps the wording UNFILLED, as email does.
        $logged = $extra + ['subject' => $subject, 'template_name' => $template->name];

        if ($recipients->patient && $customer !== null) {
            $log = $this->push->queue($organization, $customer, $title, $body, $data, $logged);

            if ($log !== null) {
                $logs[] = $log;
            }
        }

        if ($recipients->staff !== []) {
            $names = array_values($recipients->staff);
            $label = count($names) === 1 ? $names[0] : $names[0].' +'.(count($names) - 1);

            $logs[] = $this->push->queueToUsers(
                $organization,
                array_keys($recipients->staff),
                $label,
                $title,
                $body,
                $staffData ?: $data,
                $logged + ['recipient_name' => mb_substr($label, 0, 150)],
            );
        }

        return $logs;
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

        // A family member added from the app has no number or address of their
        // own: whoever books for them is who hears about their visits.
        $holder = blank($customer->email) || blank($customer->phone) ? $customer->accountHolder() : null;
        $email = filled($customer->email) ? $customer->email : $holder?->email;
        $phone = filled($customer->phone) ? $customer->phone : $holder?->phone;

        return match ($channel) {
            CommunicationChannel::EMAIL => blank($email) ? null : $this->email
                ->forOrganization($organization)
                ->queue(
                    EmailMessage::make(
                        to: (string) $email,
                        subject: (string) ($subject ?: $name),
                        body: $content,
                        template: $name,
                        variables: $variables,
                    ),
                    $customer->id,
                    $extra,
                ),

            CommunicationChannel::WHATSAPP => blank($phone) ? null : $this->whatsapp
                ->forOrganization($organization)
                ->queue(
                    WhatsAppMessage::template(
                        phone: (string) $phone,
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
