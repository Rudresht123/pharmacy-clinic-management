<?php

namespace App\Services\Push;

use App\Jobs\SendPushMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\User;
use Illuminate\Support\Carbon;

/**
 * The one door into App notifications, the way EmailManager is for email.
 *
 * Writes the delivery-log row at "queued" — so a push exists in the log from
 * the moment it was asked for, beside every email and WhatsApp — and hands
 * the job its id. The job connects the tenant, finds the person's phones in
 * the master registry, sends, and writes the outcome back onto the row.
 *
 * A patient is reached through their OWN app logins in this organization —
 * never through anything a request named. One with no app login has no
 * address on this channel, exactly as one with no email has none on email.
 */
class PushManager
{
    /**
     * The patient's own active app logins.
     *
     * @return list<int>
     */
    public function loginsFor(Customer $customer): array
    {
        return User::query()
            ->where('userable_type', Customer::class)
            ->where('userable_id', $customer->getKey())
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Queue one App notification to a patient. Null when they have no app.
     *
     * @param  array<string, scalar|null>  $data  references only — see PushMessage
     * @param  array<string, mixed>  $extra  log columns the caller owns
     */
    public function queue(
        Organization $organization,
        Customer $customer,
        string $title,
        string $body,
        array $data = [],
        array $extra = [],
    ): ?MessageLog {
        $logins = $this->loginsFor($customer);

        if ($logins === []) {
            return null;
        }

        return $this->queueToUsers(
            $organization,
            $logins,
            (string) ($customer->name ?? 'Patient'),
            $title,
            $body,
            $data,
            $extra + ['customer_id' => $customer->getKey(), 'recipient_name' => $customer->name],
        );
    }

    /**
     * Queue one App notification to people by login — a test to the person
     * sending it, for instance.
     *
     * The title and body ride on the job, not the log row: the row is what
     * support staff read, and the filled wording carries a patient's name.
     * `subject` on the row is the template's, unfilled, as email keeps it.
     *
     * @param  list<int>  $userIds
     * @param  array<string, scalar|null>  $data
     * @param  array<string, mixed>  $extra
     */
    public function queueToUsers(
        Organization $organization,
        array $userIds,
        string $recipient,
        string $title,
        string $body,
        array $data = [],
        array $extra = [],
    ): MessageLog {
        $log = MessageLog::query()->create($extra + [
            'channel' => CommunicationChannel::PUSH,
            'provider' => (string) config('push.driver', 'log'),
            'recipient' => mb_substr($recipient, 0, 150),
            'status' => MessageLog::QUEUED,
            'scheduled_for' => Carbon::now(),
            'request_payload' => ['data_keys' => array_keys($data), 'devices_for' => count($userIds)],
        ]);

        SendPushMessage::dispatch($organization->getKey(), $log->id, [
            'user_ids' => array_values($userIds),
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ])->afterCommit();

        return $log;
    }
}
