<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PatientDocument;
use App\Support\Documents\DocumentCategories;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One document, as every client reads it.
 *
 * NO URL to the stored object. The bytes are on a private disk and are served
 * by one authorised endpoint that asks the same questions this row was
 * filtered by — a signed or public link would be a way around the capability
 * check that is not even logged as an attempt.
 *
 * `download_path` is the API path to call, relative to the tenant API root,
 * so a mobile client does not have to know how paths are built here.
 */
class PatientDocumentResource extends JsonResource
{
    /** @var PatientDocument */
    public $resource;

    public function toArray(Request $request): array
    {
        $category = (string) $this->resource->category;

        return [
            'id' => $this->resource->id,
            'customer_id' => $this->resource->customer_id,
            'appointment_id' => $this->resource->appointment_id,

            'title' => $this->resource->title,
            'notes' => $this->resource->notes,

            /*
             * Scanned by somebody, or printed by this software from a
             * template. A reader has to be able to tell: a generated
             * prescription is this clinic's own statement, an uploaded one is
             * somebody else's that was filed here.
             */
            'source' => $this->resource->source,
            'document_number' => $this->resource->document_number,

            /* Which template version produced it — what makes an old document
               still readable as the document it was. Null on an upload. */
            'template_version_id' => $this->resource->document_template_version_id,

            'category' => $category,
            'category_name' => DocumentCategories::name($category),
            'is_clinical' => $this->resource->isClinical(),
            'icon' => DocumentCategories::find($category)['icon'] ?? 'ti ti-file',
            'tone' => DocumentCategories::find($category)['tone'] ?? 'slate',

            // The file, minus anything that says where it physically sits.
            'file_name' => $this->resource->file?->file_name,
            'mime_type' => $this->resource->file?->mime_type,
            'file_size' => $this->resource->file?->file_size,
            'extension' => $this->resource->file?->extension,

            'download_path' => "/tenant/documents/{$this->resource->id}/download",

            'uploaded_by_name' => $this->whenLoaded(
                'uploader',
                fn () => $this->resource->uploader?->name,
                null,
            ),
            'location_name' => $this->whenLoaded(
                'location',
                fn () => $this->resource->location?->name,
                null,
            ),

            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
