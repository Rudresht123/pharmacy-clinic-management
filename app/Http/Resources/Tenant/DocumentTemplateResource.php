<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\DocumentTemplate;
use App\Support\Documents\DocumentTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentTemplate
 */
class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = DocumentTypes::find((string) $this->document_type);

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_type_name' => $type['name'] ?? $this->document_type,
            'icon' => $type['icon'] ?? 'ti ti-file',

            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,

            /*
             * Null means this IS the organisation's default. The client reads
             * that rather than a flag, so the two can never disagree.
             */
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name, null),
            'is_organization_default' => $this->location_id === null,

            /* Both halves of "can this print" — active AND published. */
            'is_usable' => $this->isUsable(),

            'active_version' => $this->whenLoaded(
                'activeVersion',
                fn () => $this->activeVersion ? [
                    'id' => $this->activeVersion->id,
                    'version' => $this->activeVersion->version,
                    'published_at' => $this->activeVersion->published_at?->toIso8601String(),
                    'locked_fields' => $this->activeVersion->locked_fields ?? [],
                ] : null,
                null,
            ),

            'versions' => $this->whenLoaded(
                'versions',
                fn () => $this->versions->map(fn ($version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'is_published' => $version->isPublished(),
                    'is_active' => $version->id === $this->active_version_id,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'published_by_name' => $version->relationLoaded('publisher')
                        ? $version->publisher?->name
                        : null,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])->values(),
                null,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
