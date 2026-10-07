<?php

namespace App\Services\Push;

/**
 * One push notification, in the application's terms.
 *
 * The title and body are already worded — whatever chose the wording (a
 * template, at the moment of the event, on the tenant connection) has finished
 * before this exists, which is what lets the job that sends it never touch a
 * tenant database at all.
 *
 * `data` carries IDS, NOT DATA: what kind of thing happened and which record,
 * plus the organization's uuid so the app can ignore a push for a workspace it
 * has since signed out of. The app fetches anything else through the API, with
 * its own permissions. FCM requires every data value to be a string.
 */
final class PushMessage
{
    /** @var array<string, string> */
    public readonly array $data;

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        array $data = [],
    ) {
        $this->data = array_map(
            fn ($value) => $value === null ? '' : (string) $value,
            $data,
        );
    }

    /** Safe for a log line: the kind of message and its references, never its wording. */
    public function forLog(): array
    {
        return ['type' => $this->data['type'] ?? null, 'data_keys' => array_keys($this->data)];
    }
}
