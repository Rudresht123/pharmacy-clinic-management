<?php

namespace App\Services\Communication;

/**
 * Everybody one rule's message goes to this time, worked out
 * (RecipientResolver) and ready to hand to a channel (ChannelSender::queueTo).
 *
 * Email by address — To, CC and BCC, each address once, on the highest list
 * it was named on. An App notification by login — the patient's, and the staff
 * named. WhatsApp only ever asks whether the patient is on it.
 */
final class Recipients
{
    /**
     * @param  array<string, string>  $to  address => name
     * @param  array<string, string>  $cc
     * @param  array<string, string>  $bcc
     * @param  array<int, string>  $staff  login id => name, for an App notification
     * @param  string|null  $patientAddress  the patient's own address, when they are on the email
     */
    public function __construct(
        public readonly bool $patient = false,
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $staff = [],
        public readonly ?string $patientAddress = null,
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->patient && $this->to === [] && $this->cc === [] && $this->bcc === [] && $this->staff === [];
    }

    /**
     * The emails to send — normally one, carrying everybody.
     *
     * Nobody reachable on To (the patient has no address, say): CC moves up.
     * Only BCC left: one email each, because a hidden recipient who is the
     * only one on the email is not hidden from anybody — but sending the
     * others' addresses to them would be.
     *
     * @return list<array{to: array<string, string>, cc: array<string, string>, bcc: array<string, string>}>
     */
    public function envelopes(): array
    {
        if ($this->to !== []) {
            return [['to' => $this->to, 'cc' => $this->cc, 'bcc' => $this->bcc]];
        }

        if ($this->cc !== []) {
            return [['to' => $this->cc, 'cc' => [], 'bcc' => $this->bcc]];
        }

        $each = [];

        foreach ($this->bcc as $address => $name) {
            $each[] = ['to' => [$address => $name], 'cc' => [], 'bcc' => []];
        }

        return $each;
    }
}
