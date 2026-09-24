<?php

namespace App\Services\WhatsApp;

use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\WhatsAppTemplateProvider;

/**
 * Turns a logical template key into what a provider will accept.
 *
 * Two things come back: what this provider calls the template, and the order
 * its variables go in. The second matters more than it looks — every provider
 * is positional underneath, so the order is what lets business code pass
 * NAMED variables in any order and still produce the right message.
 *
 * The order is read from the template body rather than stored beside it. The
 * body is the one place that cannot disagree with itself: if the wording says
 * the patient first and the doctor second, that IS the order, and a stored
 * list would be a second copy to keep in step.
 */
class TemplateResolver
{
    /**
     * @return array{name: string, order: list<string>}|null
     *         null when the clinic has no such template, or has one the
     *         provider has not approved
     */
    public function resolve(string $templateKey, string $provider): ?array
    {
        $template = MessageTemplate::query()
            ->where('channel', 'whatsapp')
            ->whereRaw('lower(name) = ?', [mb_strtolower($templateKey)])
            ->first();

        if ($template === null) {
            return null;
        }

        $mapping = WhatsAppTemplateProvider::query()
            ->sendable($provider)
            ->where('message_template_id', $template->id)
            ->first();

        /*
         * No mapping is not an error.
         *
         * A clinic on one provider whose template names match it exactly —
         * the common case, and what the seeder produces — should not have to
         * fill in a mapping table to send anything. The mapping exists for
         * where the names DIFFER, which is a second provider or a migration.
         *
         * A mapping that exists and is NOT approved is different, and is
         * refused: the provider has told us it will not carry this.
         */
        if ($mapping === null) {
            $rejected = WhatsAppTemplateProvider::query()
                ->where('message_template_id', $template->id)
                ->where('provider', $provider)
                ->exists();

            if ($rejected) {
                return null;
            }
        }

        return [
            'name' => $mapping?->reference() ?? $template->name,
            'order' => $this->orderFor($template),
        ];
    }

    /**
     * The variable names, in the order the template's own wording uses them.
     *
     * A name that appears twice takes its FIRST position and is not repeated —
     * providers substitute one value per slot, and counting the same variable
     * twice would shift every later field by one.
     *
     * @return list<string>
     */
    public function orderFor(MessageTemplate $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template->content, $matches);

        /** @var list<string> $names */
        $names = $matches[1] ?? [];

        return array_values(array_unique($names));
    }
}
