<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PharmacySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PharmacySetting
 */
class PharmacySettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invoice_prefix' => $this->invoice_prefix,
            'round_off_enabled' => (bool) $this->round_off_enabled,

            'price_basis' => $this->price_basis,
            'prices_include_tax' => (bool) $this->prices_include_tax,

            'expiry_warning_days' => (int) $this->expiry_warning_days,

            'allow_walk_in' => (bool) $this->allow_walk_in,
            'credit_sales_enabled' => (bool) $this->credit_sales_enabled,
            'require_prescription' => (bool) $this->require_prescription,

            'default_payment_method' => $this->default_payment_method,

            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by_name' => $this->whenLoaded('editor', fn () => $this->editor?->name),
        ];
    }
}
