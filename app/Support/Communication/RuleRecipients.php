<?php

namespace App\Support\Communication;

use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;

/**
 * Who an automation rule is addressed to — the vocabulary, not the people.
 *
 * A rule names PLACES rather than people wherever it can: "the visit's doctor"
 * is somebody different on every booking, and naming Dr. Anjali by hand on the
 * booking rule would tell her about everybody else's patients too.
 * App\Services\Communication\RecipientResolver turns these into addresses and
 * logins at the moment of sending.
 *
 *   patient          the patient the event is about (patient rules only)
 *   doctor           the doctor on it — the one doing the work
 *   creator          whoever made the record: booked the visit, raised the bill
 *   branch_manager   the manager named on the event's branch, and whoever holds
 *                    that branch's manager role
 *   front_desk       everybody else at that branch who answers reschedule
 *                    requests: the screen calls them Reception
 *   role             everybody holding one role there (`id`)
 *   user             one named member of staff (`id`)
 *   email            an address typed in (`address`; email only)
 *
 * Email has To, CC and BCC. An App notification has only To — nobody is
 * copied on a push — and WhatsApp goes to the patient's own number and nobody
 * else's, so it has no list at all.
 */
final class RuleRecipients
{
    public const PATIENT = 'patient';

    public const DOCTOR = 'doctor';

    public const CREATOR = 'creator';

    public const BRANCH_MANAGER = 'branch_manager';

    public const FRONT_DESK = 'front_desk';

    public const ROLE = 'role';

    public const USER = 'user';

    public const EMAIL = 'email';

    public const TYPES = [
        self::PATIENT,
        self::DOCTOR,
        self::CREATOR,
        self::BRANCH_MANAGER,
        self::FRONT_DESK,
        self::ROLE,
        self::USER,
        self::EMAIL,
    ];

    /** The ones that are a place on the record, as the rule screen names them. */
    public const PLACES = [
        self::PATIENT => 'Patient',
        self::DOCTOR => 'Doctor',
        self::CREATOR => 'Booked by',
        self::BRANCH_MANAGER => 'Branch manager',
        self::FRONT_DESK => 'Reception',
    ];

    public const TO = 'to';

    public const CC = 'cc';

    public const BCC = 'bcc';

    /** Highest first: somebody on two of them is told once, on the higher. */
    public const FIELDS = [self::TO, self::CC, self::BCC];

    /** @return list<string> the lists a rule on [$channel] may have */
    public static function fieldsFor(string $channel): array
    {
        return match ($channel) {
            CommunicationChannel::EMAIL => [self::TO, self::CC, self::BCC],
            CommunicationChannel::PUSH => [self::TO],
            default => [],
        };
    }

    /**
     * A rule's lists, every field present, as the resolver reads them.
     *
     * Null is the patient alone — every patient rule before there was a
     * choice, and every one the optional-module seeder still writes. A list
     * a channel cannot carry (CC on a push) is dropped rather than obeyed.
     *
     * @return array{to: list<array<string, mixed>>, cc: list<array<string, mixed>>, bcc: list<array<string, mixed>>}
     */
    public static function of(AutomationRule $rule): array
    {
        $saved = $rule->recipients ?? ($rule->isForStaff() ? [] : [self::TO => [['type' => self::PATIENT]]]);
        $fields = self::fieldsFor((string) $rule->channel);

        $lists = [];

        foreach (self::FIELDS as $field) {
            $lists[$field] = in_array($field, $fields, true) ? array_values($saved[$field] ?? []) : [];
        }

        // A WhatsApp message is the patient's, on their own number.
        if ($fields === [] && ! $rule->isForStaff()) {
            $lists[self::TO] = [['type' => self::PATIENT]];
        }

        return $lists;
    }
}
