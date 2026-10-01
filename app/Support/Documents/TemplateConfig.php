<?php

namespace App\Support\Documents;

use Illuminate\Support\Arr;

/**
 * The shape of a template, and what a new one starts as.
 *
 * ONE DOCUMENT, not a column per setting. What a template can carry grows
 * every time the renderer learns something, and a migration per new field is
 * how a design like this ossifies — so the whole thing is a jsonb column and
 * this class is what says the structure out loud.
 *
 * Four sections, which is how somebody thinks about a printed page:
 *
 *   header   the letterhead — logo, who this clinic is, what the document is
 *   body     what of the record actually prints
 *   footer   terms, signature, the small print
 *   layout   paper, margins, type size
 *
 * Paths into it are dotted — `header.legal_name`, `layout.paper` — and that is
 * also the vocabulary of LOCKED FIELDS: an organization locks a path, and a
 * branch may not change it or anything beneath it.
 */
class TemplateConfig
{
    /**
     * What an organization default starts as, per document type.
     *
     * Deliberately usable as-is. A template that has to be filled in before
     * it prints anything is a template nobody finishes, and the commonest
     * outcome of that is a clinic printing nothing at all.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(string $typeKey): array
    {
        $type = DocumentTypes::find($typeKey);
        $paper = $type['paper'] ?? DocumentTypes::PAPER_A4;
        $receipt = $paper === DocumentTypes::PAPER_RECEIPT;

        return [
            'header' => [
                'show_logo' => ! $receipt,
                /* branch | organization | none — a branch with no logo of its
                   own falls back to the organization's, which is the same
                   inheritance the templates themselves use. */
                'logo_source' => 'branch',
                'logo_position' => $receipt ? 'center' : 'left',
                'title' => $type['name'] ?? 'Document',
                /*
                 * ONE FACT PER LINE, because the letterhead prints each with
                 * its own icon — an address behind a pin, a number behind a
                 * handset. The old default ran the phone and the email
                 * together on one row, which is the part of a letterhead
                 * nobody can find anything in.
                 */
                'lines' => $receipt
                    ? ['{{branch_phone}}']
                    : ['{{branch_address}}', '{{branch_phone}}', '{{branch_email}}'],
                /* Typically locked by the organization: the registered name
                   and number are the organization's to state, not a branch's
                   to reword. */
                'legal_name' => '{{organization_name}}',
                /* The line under the clinic's name. The branch by default,
                   because that is what distinguishes two sheets of the same
                   letterhead; a clinic that prints departments rather than
                   branches types its own. */
                'department' => '{{branch_name}}',
                /* The practice's own line — "Better care, healthier
                   tomorrow." Blank by default: an invented one would print on
                   every bill a clinic sends out. */
                'tagline' => '',
                /* A line under the document's title, inside the coloured
                   band — what this particular sheet is for. */
                'subtitle' => '',
                'registration_no' => '',
                'show_divider' => true,
            ],

            'body' => [
                'show_patient' => true,
                'show_visit' => in_array('visit', $type['groups'] ?? [], true),
                'show_clinical' => in_array('clinical', $type['groups'] ?? [], true),
                /* The tables this type prints. Empty for a document that is
                   all prose. */
                'tables' => self::tablesFor($typeKey),
                'intro' => '',
                'notes' => '',
            ],

            'footer' => [
                /*
                 * The FIRST line is the clinic's own, set in the accent inside
                 * the footer band; anything after it is small print beneath.
                 * A closing line rather than none, because the band is part of
                 * the layout and an empty one looks like a missing setting.
                 */
                'lines' => $receipt
                    ? ['Thank you']
                    : ['Thank you for choosing {{organization_name}}.'],
                'terms' => '',
                'show_signature' => ! $receipt,
                'signature_label' => in_array('visit', $type['groups'] ?? [], true)
                    ? 'Doctor’s signature'
                    : 'Authorised signature',
                'show_page_numbers' => ! $receipt,
            ],

            'layout' => [
                'paper' => $paper,
                'margin_mm' => $receipt
                    ? ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4]
                    : ['top' => 14, 'right' => 12, 'bottom' => 14, 'left' => 12],
                /*
                 * A tax invoice carries seven columns — number, item, HSN,
                 * quantity, rate, tax, amount — and at 11pt on A5 that runs
                 * onto a second page for a bill of six lines. 9pt is what
                 * fits a real counter's invoice on one sheet; the prose
                 * documents keep the larger, more readable size.
                 */
                /*
                 * An invoice carries seven columns — number, item, HSN,
                 * quantity, rate, tax, amount — and sets tighter than the
                 * prose documents so a real bill's worth of lines fits on
                 * one sheet.
                 */
                'font_size' => match (true) {
                    $receipt => 9,
                    in_array($typeKey, ['clinic_invoice', 'pharmacy_invoice', 'clinic_receipt', 'clinic_refund'], true) => 10,
                    default => 11,
                },
                'font_family' => 'sans-serif',
                'accent' => '#2e37a4',
            ],
        ];
    }

    /**
     * Which repeating block a document type prints, if any.
     *
     * A table is one placeholder that becomes many rows, which is the whole
     * difference between a prescription and a letter.
     *
     * @return list<array{token: string, title: string}>
     */
    private static function tablesFor(string $typeKey): array
    {
        return match ($typeKey) {
            'prescription' => [['token' => 'prescription_items', 'title' => 'Medicines']],
            /*
             * The clinic's own bill prints "Charges" rather than "Items":
             * what is on it is a consultation, a test and a procedure as
             * often as it is a box of tablets, and "Items" reads as a
             * shopping list of the one case it is not.
             */
            'clinic_invoice' => [['token' => 'invoice_items', 'title' => 'Charges']],
            'pharmacy_invoice', 'payment_receipt' => [['token' => 'invoice_items', 'title' => 'Items']],
            'document_cover' => [['token' => 'document_list', 'title' => 'Documents on file']],
            default => [],
        };
    }

    /**
     * The paths an organization may lock.
     *
     * A closed list, not "any path somebody sends". A lock on a path the
     * renderer does not read would look like protection and be none, and
     * offering the whole tree would let somebody lock `layout.margin_mm.top`
     * — which is a lock nobody meant and nobody can find again.
     *
     * Section-level entries lock everything beneath them; see
     * DocumentTemplateVersion::locks().
     *
     * @return list<array{path: string, label: string}>
     */
    public static function lockablePaths(): array
    {
        return [
            ['path' => 'header.legal_name', 'label' => 'Registered clinic name'],
            ['path' => 'header.registration_no', 'label' => 'Registration / licence number'],
            ['path' => 'header.logo_source', 'label' => 'Which logo is printed'],
            ['path' => 'header.title', 'label' => 'Document title'],
            ['path' => 'header', 'label' => 'The whole header'],
            ['path' => 'footer.terms', 'label' => 'Terms and disclaimer'],
            ['path' => 'footer', 'label' => 'The whole footer'],
            ['path' => 'layout.paper', 'label' => 'Paper size'],
            ['path' => 'layout.accent', 'label' => 'Accent colour'],
            ['path' => 'layout', 'label' => 'The whole layout'],
        ];
    }

    /** @return list<string> */
    public static function lockableKeys(): array
    {
        return array_column(self::lockablePaths(), 'path');
    }

    /**
     * Every leaf path in a config, for comparing two of them.
     *
     * @return array<string, mixed> dotted path => value
     */
    public static function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            /*
             * A list is a LEAF, not a branch. `header.lines` is one setting
             * somebody edits as a whole — flattening it to `header.lines.0`
             * would report "line 3 changed" when somebody deleted line 2, and
             * would make a lock on it meaningless.
             */
            if (is_array($value) && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * Which settings differ between two configs.
     *
     * Used to check a branch's edit against the organization's locks: what
     * matters is not what was SENT but what was CHANGED, so a branch echoing
     * a locked value back unchanged is not an attempt to change it.
     *
     * @return list<string> dotted paths
     */
    public static function changedPaths(array $before, array $after): array
    {
        $a = self::flatten($before);
        $b = self::flatten($after);

        $changed = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $path) {
            if (($a[$path] ?? null) !== ($b[$path] ?? null)) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * Drop anything the shape does not know about.
     *
     * A config is written by a client, and a jsonb column will store whatever
     * it is handed — including a key the renderer never reads, which would sit
     * there looking like a setting that does nothing. Keys are kept only where
     * the defaults have them.
     *
     * @return array<string, mixed>
     */
    public static function sanitise(array $config, string $typeKey): array
    {
        $defaults = self::defaultsFor($typeKey);
        $clean = [];

        foreach ($defaults as $section => $shape) {
            if (! is_array($shape)) {
                $clean[$section] = Arr::get($config, $section, $shape);

                continue;
            }

            foreach ($shape as $key => $fallback) {
                $value = Arr::get($config, "{$section}.{$key}", $fallback);

                /*
                 * Null becomes the empty string where the shape says string.
                 *
                 * Laravel's ConvertEmptyStringsToNull turns a blank field in
                 * the request into null, while the same field read back from
                 * jsonb is ''. Left alone the two never match, so every save
                 * reported an untouched blank field as CHANGED — and on a
                 * LOCKED blank field that meant the template could never be
                 * saved again by anybody it was locked against.
                 */
                if ($value === null && is_string($fallback)) {
                    $value = '';
                }

                $clean[$section][$key] = $value;
            }
        }

        return $clean;
    }
}
