<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageLog
 */
class MessageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,

            // The copies, not the patient's current details — where a message
            // actually went is history.
            'recipient_name' => $this->recipient_name,
            'recipient' => $this->recipient,
            'template_name' => $this->template_name,
            'subject' => $this->subject,

            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'is_test' => (bool) $this->is_test,

            'customer_id' => $this->customer_id,
            'campaign_id' => $this->campaign_id,

            /*
             * Every step, not just the last one. "Delivered at 2:29 and read
             * at 4:05" is a different answer from "read", and it is the one
             * that settles an argument about whether a patient was told.
             */
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
