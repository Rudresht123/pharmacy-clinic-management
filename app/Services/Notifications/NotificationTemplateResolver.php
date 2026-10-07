<?php

namespace App\Services\Notifications;

use App\Models\Platform\GlobalNotificationTemplate;
use App\Models\Tenant\NotificationTemplate;
use App\Support\Notifications\NotificationTypes;
use InvalidArgumentException;

/**
 * Which wording a notification goes out in — the inheritance chain, in one
 * place.
 *
 *   1. this branch's own template, if it has an active one
 *   2. otherwise the organisation's
 *   3. otherwise the platform's global one
 *   4. otherwise the code default in NotificationTypes
 *
 * Inactive templates are skipped, not obeyed: switching a branch's template
 * off means "use the organisation's again", never "send nothing".
 *
 * Named apart from the two TemplateResolvers that already exist (documents,
 * WhatsApp) — three classes called TemplateResolver is how somebody edits
 * the wrong one.
 *
 * Reads the tenant connection the caller is on, and the master one for the
 * global level.
 */
class NotificationTemplateResolver
{
    public const BRANCH = 'branch';

    public const ORGANIZATION = 'organization';

    public const GLOBAL = 'global';

    public const DEFAULT = 'default';

    /**
     * @return array{title: string, body: string, source: string}
     */
    public function resolve(string $key, ?int $locationId = null): array
    {
        if (! NotificationTypes::has($key)) {
            throw new InvalidArgumentException("There is no notification type called [{$key}].");
        }

        $rows = NotificationTemplate::query()
            ->where('template_key', $key)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('location_id')
                ->when($locationId !== null, fn ($query) => $query->orWhere('location_id', $locationId)))
            ->get();

        $branch = $locationId === null ? null : $rows->firstWhere('location_id', $locationId);

        if ($branch !== null) {
            return $this->answer($branch->title, $branch->body, self::BRANCH);
        }

        $organization = $rows->first(fn (NotificationTemplate $row) => $row->location_id === null);

        if ($organization !== null) {
            return $this->answer($organization->title, $organization->body, self::ORGANIZATION);
        }

        $global = GlobalNotificationTemplate::query()
            ->where('template_key', $key)
            ->where('is_active', true)
            ->first();

        if ($global !== null) {
            return $this->answer($global->title, $global->body, self::GLOBAL);
        }

        $type = NotificationTypes::find($key);

        return $this->answer($type['title'], $type['body'], self::DEFAULT);
    }

    /**
     * The resolved wording with its blanks filled.
     *
     * @param  array<string, scalar|null>  $values
     * @return array{title: string, body: string, source: string}
     */
    public function render(string $key, ?int $locationId, array $values): array
    {
        $template = $this->resolve($key, $locationId);

        return [
            'title' => NotificationTypes::render($template['title'], $values),
            'body' => NotificationTypes::render($template['body'], $values),
            'source' => $template['source'],
        ];
    }

    /** @return array{title: string, body: string, source: string} */
    private function answer(string $title, string $body, string $source): array
    {
        return ['title' => $title, 'body' => $body, 'source' => $source];
    }
}
