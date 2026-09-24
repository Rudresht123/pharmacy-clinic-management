<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'name' => $this->name,
            'campaign_type' => $this->campaign_type,
            'goal' => $this->goal,

            'subject' => $this->subject,
            'preview_text' => $this->preview_text,
            'content' => $this->content,

            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'reply_to' => $this->reply_to,

            'message_template_id' => $this->message_template_id,
            'template_name' => $this->whenLoaded('template', fn () => $this->template?->name),

            'audience_segment_id' => $this->audience_segment_id,
            'segment_name' => $this->whenLoaded('segment', fn () => $this->segment?->name),
            'audience_filters' => $this->audience_filters ?? [],

            'status' => $this->status,
            'is_editable' => $this->isEditable(),

            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'recipients_count' => (int) $this->recipients_count,
            'sent_count' => (int) $this->sent_count,
            'delivered_count' => (int) $this->delivered_count,
            'opened_count' => (int) $this->opened_count,
            'clicked_count' => (int) $this->clicked_count,
            'failed_count' => (int) $this->failed_count,

            // Rates against what they are a proportion of, worked out once
            // here rather than in every screen that shows a campaign.
            'open_rate' => $this->openRate(),
            'click_rate' => $this->clickRate(),

            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
