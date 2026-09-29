<?php

namespace App\Services\Documents;

/**
 * One organisation's decision: on this event, produce this document, do these
 * things with it.
 *
 * A VALUE OBJECT, NOT A MODEL. In Step 1 nothing persists these — DocumentRules
 * returns none — and when `document_rules` arrives in Step 3 the model maps
 * into this rather than being passed around itself. That keeps the automation
 * testable without a table, and keeps a row's shape from leaking into every
 * caller the moment somebody adds a column.
 */
final class DocumentRule
{
    public function __construct(
        /** A key from DocumentTypes — what to produce. */
        public readonly string $documentType,
        /** False means the clinic wants this on a button, not automatically. */
        public readonly bool $autoGenerate = true,
        public readonly bool $print = false,
        public readonly bool $whatsapp = false,
        public readonly bool $email = false,
        public readonly bool $portal = false,
        /** Null lets TemplateResolver pick the branch's active template. */
        public readonly ?int $templateId = null,
    ) {}

    /** Whether anything at all is meant to leave the building. */
    public function delivers(): bool
    {
        return $this->print || $this->whatsapp || $this->email || $this->portal;
    }
}
