<?php

namespace App\Services\Documents;

use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\DocumentTemplateVersion;

/**
 * Which template a document is printed from, and which version of it.
 *
 * Two questions, asked in one order, with NO third answer:
 *
 *   1. Has this branch customised this document type, and is that customised
 *      template active with a published version? Use it.
 *   2. Otherwise, the organization's default, on the same two conditions.
 *   3. Otherwise refuse.
 *
 * The refusal is the important part. "Fall back to the latest template" is the
 * obvious next line and it is wrong twice over: a DRAFT is not a document
 * anybody approved, and an ARCHIVED one is a template somebody deliberately
 * took out of use. Printing from either would put a document into a patient's
 * hands that nobody chose.
 *
 * What comes back is the VERSION, not the template. A generated document
 * records the version id, which is what makes a prescription printed in
 * September still readable as the document it was.
 */
class TemplateResolver
{
    /**
     * @return array{template: DocumentTemplate, version: DocumentTemplateVersion}|null
     *         null when this organization has nothing usable for this type
     */
    public function resolve(string $documentType, ?int $locationId): ?array
    {
        /*
         * The branch's own first. Ordered by `location_id` DESC NULLS LAST so
         * an override sorts ahead of the default in ONE query — two queries
         * would be two round trips and a chance for them to disagree about
         * what "active" means.
         */
        $candidates = DocumentTemplate::query()
            ->with('activeVersion')
            ->where('document_type', $documentType)
            ->where('status', DocumentTemplate::ACTIVE)
            ->whereNotNull('active_version_id')
            ->forBranch($locationId)
            ->get();

        $branchTemplate = $locationId === null
            ? null
            : $candidates->firstWhere('location_id', $locationId);

        $template = $branchTemplate ?? $candidates->firstWhere('location_id', null);

        if (! $template || ! $template->activeVersion) {
            return null;
        }

        return ['template' => $template, 'version' => $template->activeVersion];
    }

    /**
     * The organization's default for a type, whatever its state.
     *
     * Not for printing — this is what the LOCKS are read from, and a branch
     * must be held to the organization's locks even while the organization's
     * own template is a draft. Otherwise unpublishing the default would
     * quietly unlock every branch.
     */
    public function organizationDefault(string $documentType): ?DocumentTemplate
    {
        return DocumentTemplate::query()
            ->with('activeVersion', 'versions')
            ->whereNull('location_id')
            ->where('document_type', $documentType)
            ->first();
    }

    /**
     * The locks a branch is held to for this document type.
     *
     * Read from the organization default's ACTIVE version where there is one,
     * and from its newest version otherwise — see above. Empty when the
     * organization has never written a default, which means a branch is free.
     *
     * @return list<string> dotted paths
     */
    public function locksFor(string $documentType): array
    {
        $default = $this->organizationDefault($documentType);

        if (! $default) {
            return [];
        }

        $version = $default->activeVersion ?? $default->versions->first();

        return $version?->locked_fields ?? [];
    }
}
