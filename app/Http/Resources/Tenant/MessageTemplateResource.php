<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageTemplate
 */
class MessageTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'name' => $this->name,
            'category' => $this->category,
            'template_type' => $this->template_type,

            'subject' => $this->subject,
            'content' => $this->content,

            'status' => $this->status,
            'is_active' => (bool) $this->is_active,

            // The screen lists templates a clinic wrote, so a row has to be
            // able to look like itself without the client keeping a map.
            'icon' => $this->icon,
            'tone' => $this->tone,

            // The platform's default (seeded, nobody wrote it) or the clinic's
            // own — the App notifications screen lists them apart.
            'is_default' => $this->created_by === null,

            // How many branches send their own wording, where it was counted.
            'branch_wordings' => $this->whenCounted('variants'),

            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by_name' => $this->whenLoaded('editor', fn () => $this->editor?->name),
        ];
    }
}
