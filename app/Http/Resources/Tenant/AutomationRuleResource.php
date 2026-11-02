<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Communication\RuleRecipients;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'event_key' => $this->event_key,
            'audience' => $this->audience ?? AutomationRule::PATIENT,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,

            'is_enabled' => (bool) $this->is_enabled,
            'lead_minutes' => $this->lead_minutes,

            'message_template_id' => $this->message_template_id,
            'template_name' => $this->whenLoaded('template', fn () => $this->template?->name),

            'recipients' => $this->labelled(RuleRecipients::of($this->resource)),
        ];
    }

    /**
     * Each list with what the screen calls every entry — "Doctor",
     * "Receptionist", "Priya Sharma", an address. A role or a person that
     * has since gone says so rather than vanishing from the list.
     *
     * @param  array<string, list<array<string, mixed>>>  $lists
     * @return array<string, list<array<string, mixed>>>
     */
    private function labelled(array $lists): array
    {
        $roles = $this->names(Role::query(), $lists, RuleRecipients::ROLE);
        $users = $this->names(User::query(), $lists, RuleRecipients::USER);

        return array_map(fn (array $entries) => array_map(fn (array $entry) => $entry + [
            'label' => match ($entry['type']) {
                RuleRecipients::ROLE => $roles[$entry['id'] ?? 0] ?? 'A role that was removed',
                RuleRecipients::USER => $users[$entry['id'] ?? 0] ?? 'Somebody no longer on staff',
                RuleRecipients::EMAIL => (string) ($entry['address'] ?? ''),
                default => RuleRecipients::PLACES[$entry['type']] ?? $entry['type'],
            },
        ], $entries), $lists);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $lists
     * @return array<int, string>
     */
    private function names($query, array $lists, string $type): array
    {
        $ids = [];

        foreach ($lists as $entries) {
            foreach ($entries as $entry) {
                if ($entry['type'] === $type && isset($entry['id'])) {
                    $ids[] = (int) $entry['id'];
                }
            }
        }

        return $ids === [] ? [] : $query->whereKey($ids)->pluck('name', 'id')->all();
    }
}
