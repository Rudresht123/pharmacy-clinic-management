<?php

namespace App\Services\Documents;

use App\Support\Documents\DocumentTypes;
use Illuminate\Support\Arr;

/**
 * A template's config plus a document's data, as one HTML page.
 *
 * Deliberately separate from the PDF library. This class knows what a
 * prescription looks like; PdfRenderer knows how to turn HTML into a PDF and
 * nothing else. Swapping the library later is one file, and this one is
 * testable without rendering anything.
 *
 * TABLES, NOT FLEX. dompdf — the renderer behind this — supports a small,
 * old subset of CSS: no flexbox, no grid, limited positioning. Building the
 * layout from tables from the start is not nostalgia, it is the difference
 * between a letterhead that prints and one that looks right in a browser and
 * collapses in the PDF.
 *
 * Every value is escaped. A patient's name and a doctor's notes are free text
 * that ends up inside markup, and a document that renders somebody's typed
 * `<b>` as bold is the small version of the same bug that renders a script
 * tag.
 */
class DocumentHtml
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array{values: array<string, string>, tables: array<string, mixed>}  $payload
     */
    public function render(string $documentType, array $config, array $payload, ?string $logoData = null): string
    {
        $values = $payload['values'] ?? [];
        $tables = $payload['tables'] ?? [];

        $layout = Arr::get($config, 'layout', []);
        $receipt = Arr::get($layout, 'paper') === DocumentTypes::PAPER_RECEIPT;

        $sections = [
            $this->header($config, $values, $logoData, $receipt),
            $this->body($config, $values, $tables, $receipt),
            $this->footer($config, $values, $receipt),
        ];

        return $this->page($layout, implode("\n", array_filter($sections)), $receipt);
    }

    /** @param array<string, mixed> $layout */
    private function page(array $layout, string $content, bool $receipt): string
    {
        $margins = Arr::get($layout, 'margin_mm', []);
        $accent = $this->colour(Arr::get($layout, 'accent', '#2e37a4'));
        $size = (float) (Arr::get($layout, 'font_size', 11) ?: 11);

        /*
         * DejaVu, NOT the CSS generic families.
         *
         * `sans-serif` resolves to one of dompdf's built-in PDF core fonts,
         * which are Latin-1 only — and the rupee sign is not in Latin-1. It
         * printed as "?" on every bill, which on a money document is the one
         * character that must never be wrong. DejaVu is bundled with dompdf
         * and carries ₹ along with the Devanagari-adjacent punctuation these
         * documents use, so it is named explicitly rather than hoped for.
         *
         * The generic name is kept as a fallback after it, so a deployment
         * that somehow lacks DejaVu still renders text rather than nothing.
         */
        $family = Arr::get($layout, 'font_family') === 'serif'
            ? "'DejaVu Serif', serif"
            : "'DejaVu Sans', sans-serif";

        $margin = sprintf(
            '%smm %smm %smm %smm',
            (float) ($margins['top'] ?? 14),
            (float) ($margins['right'] ?? 12),
            (float) ($margins['bottom'] ?? 14),
            (float) ($margins['left'] ?? 12),
        );

        /*
         * A receipt is a continuous strip, not a paper size. 80mm wide and as
         * long as it needs to be — a till roll has no page two, and giving it
         * an A4 page height would print one line and eject a blank sheet.
         */
        $page = $receipt
            ? '@page { size: 80mm auto; margin: '.$margin.'; }'
            : '@page { margin: '.$margin.'; }';

        /*
         * Two derived shades, mixed in PHP rather than left to CSS.
         *
         * dompdf's support for colour functions is not something to rely on,
         * and a header band whose background silently failed to render would
         * print white text on white paper. Mixing with white here means the
         * value that reaches the renderer is a plain hex it cannot get wrong.
         */
        $tint = $this->mix($accent, 0.92);
        $edge = $this->mix($accent, 0.75);

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <style>
            {$page}
            /*
             * 1.3, not 1.45. DejaVu sets considerably wider and taller than
             * the core font this layout was first drawn against — at the
             * looser leading a three-line invoice ran onto a second page,
             * which on a bill handed across a counter is a whole extra sheet
             * carrying a signature line and nothing else.
             */
            body { font-family: {$family}; font-size: {$size}pt; color: #1a1a1a; margin: 0;
                   line-height: 1.3; }
            table { width: 100%; border-collapse: collapse; }
            .accent { color: {$accent}; }
            .muted { color: #6b7280; font-size: {$this->scale($size, 0.85)}pt; }

            /* ---- letterhead ------------------------------------------- */
            .org { font-size: {$this->scale($size, 1.3)}pt; font-weight: bold;
                   color: {$accent}; line-height: 1.15; }
            .org-sub { color: #6b7280; font-size: {$this->scale($size, 0.85)}pt; }
            .lines td { padding: 0; }

            /*
             * The document's own name, set against the letterhead rather than
             * under it. On a bill the thing somebody is holding — "Invoice",
             * and its number — is what they look for first, and burying it as
             * one more grey line under the address is why the old layout read
             * as a letter rather than as a document.
             */
            /*
             * `nowrap` because "CLINIC INVOICE" breaking across two lines
             * beside the clinic's name reads as a layout that gave up. If it
             * genuinely will not fit, one long title overflowing its column
             * is a better failure than a stacked one.
             */
            .doc-name { font-size: {$this->scale($size, 1.15)}pt; font-weight: bold;
                        color: {$accent}; text-transform: uppercase;
                        line-height: 1.15; white-space: nowrap; }
            .doc-no { font-size: {$this->scale($size, 0.85)}pt; color: #374151;
                      white-space: nowrap; }

            .rule { border-bottom: 2px solid {$accent}; height: 0; margin: 7px 0 0; }
            .rule-soft { border-bottom: 1px solid #e5e7eb; height: 0; margin: 0 0 11px; }

            /* ---- the who/when panel ------------------------------------ */
            .panel { background: {$tint}; border: 1px solid {$edge}; margin: 7px 0; }
            .panel td { padding: 4px 7px; vertical-align: top; }
            .panel .col { width: 50%; }
            .pair td { padding: 0; border: 0; }
            /* nowrap on the label, so "Visit date" stays one line and the
               value beside it keeps its own row rather than being pushed. */
            .pair .k { color: #6b7280; width: 32%; white-space: nowrap;
                       font-size: {$this->scale($size, 0.82)}pt; padding-right: 5px; }
            .pair .v { font-weight: bold; font-size: {$this->scale($size, 0.92)}pt; }

            /* ---- section heads ----------------------------------------- */
            .sec { font-weight: bold; color: {$accent}; font-size: {$this->scale($size, 0.9)}pt;
                   text-transform: uppercase;
                   border-bottom: 1px solid {$edge}; padding-bottom: 2px; margin: 8px 0 4px; }

            /* ---- the items table --------------------------------------- */
            .grid { margin: 0 0 4px; }
            .grid th { background: {$accent}; color: #fff; padding: 4px 6px; text-align: left;
                       font-size: {$this->scale($size, 0.82)}pt; font-weight: bold;
                       border: 1px solid {$accent}; }
            .grid td { border: 1px solid #e5e7eb; padding: 3px 6px; vertical-align: top;
                       font-size: {$this->scale($size, 0.88)}pt; }
            /* Zebra, so a wide row is readable across without a ruler. */
            .grid tr.alt td { background: #fafafa; }
            .grid .num { text-align: right; }
            /* The category heading inside the table — a band, not a row of
               data, so it carries the accent wash and no cell borders. */
            .grid tr.grp td { background: {$tint}; color: {$accent}; font-weight: bold;
                              font-size: {$this->scale($size, 0.82)}pt;
                              padding: 2px 6px; border: 1px solid {$edge}; }

            /* ---- how it was settled ------------------------------------ */
            .pay { background: {$tint}; border: 1px solid {$edge}; padding: 6px 8px; }
            .pay .k { color: #6b7280; font-size: {$this->scale($size, 0.8)}pt;
                      white-space: nowrap; padding-right: 6px; }
            .pay .v { font-weight: bold; font-size: {$this->scale($size, 0.85)}pt; }
            .pay td { padding: 1px 0; border: 0; }

            /*
             * The stamp somebody looks for before anything else on a bill
             * they have already paid. Green for settled, amber for part
             * paid, red for owing — said once, large, and nowhere else.
             */
            .stamp-paid { text-align: center; padding: 7px 6px; font-weight: bold;
                          font-size: {$this->scale($size, 1.15)}pt; letter-spacing: 0.5pt; }
            .is-paid { background: #e8f6ee; border: 1px solid #9ad2b1; color: #1c7c47; }
            .is-part { background: #fdf3e2; border: 1px solid #e8c583; color: #9a6206; }
            .is-unpaid { background: #fdecec; border: 1px solid #efb1b1; color: #a52222; }

            /* ---- totals ------------------------------------------------ */
            .totals { margin-top: 5px; }
            .totals td { padding: 1px 8px; font-size: {$this->scale($size, 0.9)}pt; }
            .totals .k { color: #6b7280; text-align: right; white-space: nowrap; }
            .totals .v { text-align: right; font-weight: bold; width: 30mm; white-space: nowrap; }
            .totals .grand td { border-top: 1.5px solid {$accent}; padding-top: 5px;
                                font-size: {$this->scale($size, 1.02)}pt; color: {$accent}; }
            /* What is still owed, said once and loudly — a bill whose balance
               reads the same weight as its tax line is a bill people misread. */
            .totals .due td { background: {$tint}; border-top: 1px solid {$edge};
                              border-bottom: 1px solid {$edge};
                              font-size: {$this->scale($size, 0.98)}pt; }

            .block { margin-top: 8px; }
            .block .h { font-weight: bold; color: {$accent};
                        font-size: {$this->scale($size, 0.9)}pt; }

            .sign { margin-top: 12px; }
            .sign .line { border-top: 1px solid #9ca3af; padding-top: 4px; text-align: center; }

            .terms { border: 1px solid #e5e7eb; background: #fafafa; padding: 7px 9px;
                     margin-top: 13px; color: #374151;
                     font-size: {$this->scale($size, 0.82)}pt; }
            .foot { margin-top: 10px; }
            .stamp { margin-top: 9px; border-top: 1px solid #e5e7eb; padding-top: 5px;
                     color: #9ca3af; font-size: {$this->scale($size, 0.75)}pt;
                     text-align: center; }
        </style></head><body>
        {$content}
        </body></html>
        HTML;
    }

    /**
     * A lighter shade of the accent, mixed towards white.
     *
     * `$towardsWhite` of 0.92 is a wash you can print text on; 0.75 is a
     * border that reads as "related to the accent" without competing with it.
     * Done here rather than in CSS because dompdf's colour handling is not
     * worth betting a printed document on.
     */
    private function mix(string $hex, float $towardsWhite): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $blend = fn (int $channel) => (int) round($channel + (255 - $channel) * $towardsWhite);

        return sprintf('#%02x%02x%02x', $blend((int) $r), $blend((int) $g), $blend((int) $b));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function header(array $config, array $values, ?string $logoData, bool $receipt): string
    {
        $header = Arr::get($config, 'header', []);

        $legal = $this->fill(Arr::get($header, 'legal_name', ''), $values);
        $registration = $this->fill(Arr::get($header, 'registration_no', ''), $values);
        $title = $this->fill(Arr::get($header, 'title', ''), $values);

        $lines = [];

        foreach ((array) Arr::get($header, 'lines', []) as $line) {
            $filled = trim($this->fill((string) $line, $values));

            // A line whose only content was an empty placeholder is dropped
            // rather than printed as a blank row — a letterhead with a gap
            // where the phone number should be reads as a broken document.
            if ($filled !== '' && $filled !== '·') {
                $lines[] = '<tr><td>'.$this->e($filled).'</td></tr>';
            }
        }

        $logo = $logoData && Arr::get($header, 'show_logo')
            ? '<img src="'.$logoData.'" style="max-height:16mm;max-width:38mm;">'
            : '';

        $position = Arr::get($header, 'logo_position', 'left');
        $rule = Arr::get($header, 'show_divider')
            ? '<div class="rule"></div><div class="rule-soft"></div>'
            : '<div style="height:11px"></div>';

        /* Who this clinic is: name, what it is registered as, how to reach it. */
        $identity = '<div class="org">'.$this->e($legal !== '' ? $legal : $title).'</div>'
            .($registration !== '' ? '<div class="org-sub">'.$this->e($registration).'</div>' : '')
            .'<table class="lines org-sub">'.implode('', $lines).'</table>';

        /*
         * WHAT THIS DOCUMENT IS, set opposite the letterhead rather than
         * beneath it.
         *
         * Somebody holding a bill looks for "Invoice" and its number before
         * they read the clinic's address — the old layout printed the title
         * as one more grey line under the phone number, which is why it read
         * as a letter rather than as a document. Its own number and date sit
         * with it, the way every invoice anybody has ever been handed does.
         */
        $stamp = array_filter([
            $values['invoice_number'] ?? $values['prescription_number'] ?? $values['document_number'] ?? '',
            $values['invoice_date'] ?? $values['prescription_date'] ?? '',
        ], fn ($part) => trim((string) $part) !== '');

        $plate = $title !== ''
            ? '<div class="doc-name">'.$this->e($title).'</div>'
                .($stamp !== [] ? '<div class="doc-no">'.$this->e(implode(' · ', $stamp)).'</div>' : '')
            : '';

        /*
         * A till roll is 80mm of one column. Two columns of anything on it
         * would be four words per line, so everything stacks and centres.
         */
        if ($receipt || $position === 'center') {
            return '<div style="text-align:center">'.$logo.$identity.$plate.'</div>'.$rule;
        }

        [$left, $right] = $position === 'right'
            ? [$identity.$plate, $logo]
            : [$logo, $identity];

        if ($position === 'right') {
            return '<table><tr>'
                .'<td style="vertical-align:top">'.$left.'</td>'
                .'<td style="width:40mm;vertical-align:top;text-align:right">'.$right.'</td>'
                .'</tr></table>'.$rule;
        }

        return '<table><tr>'
            .($logo !== '' ? '<td style="width:40mm;vertical-align:top">'.$logo.'</td>' : '')
            .'<td style="vertical-align:top'.($logo !== '' ? ';padding-left:5mm' : '').'">'.$identity.'</td>'
            .'<td style="vertical-align:top;text-align:right;width:52mm">'.$plate.'</td>'
            .'</tr></table>'.$rule;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function body(array $config, array $values, array $tables, bool $receipt): string
    {
        $body = Arr::get($config, 'body', []);
        $out = [];

        $intro = trim($this->fill((string) Arr::get($body, 'intro', ''), $values));

        if ($intro !== '') {
            $out[] = '<div class="block">'.nl2br($this->e($intro)).'</div>';
        }

        /*
         * WHO, and WHEN — side by side in one panel.
         *
         * They used to be two stacked tables, which ran seven near-empty rows
         * down a page that then had no room for what the document was
         * actually about. They belong together: both answer "whose document
         * is this", and a reader checks them in one glance before reading
         * anything else.
         */
        $who = Arr::get($body, 'show_patient') ? [
            'Patient' => $values['patient_name'] ?? '',
            'Patient ID' => $values['patient_id'] ?? '',
            'Age / Sex' => trim(($values['patient_age'] ?? '').' / '.($values['patient_gender'] ?? ''), ' /'),
            'Mobile' => $values['patient_mobile'] ?? '',
        ] : [];

        $when = Arr::get($body, 'show_visit') ? [
            'Doctor' => $values['doctor_name'] ?? '',
            'Reg. no.' => $values['doctor_registration_number'] ?? '',
            'Visit date' => $values['visit_date'] ?? '',
        ] : [];

        if ($who !== [] || $when !== []) {
            $out[] = $this->panel($who, $when, $receipt);
        }

        if (Arr::get($body, 'show_clinical')) {
            foreach ([
                'Complaint' => $values['symptoms'] ?? '',
                'Diagnosis' => $values['diagnosis'] ?? '',
                'Vitals' => $values['vitals'] ?? '',
            ] as $label => $value) {
                if (trim((string) $value) !== '') {
                    $out[] = '<div class="sec">'.$this->e($label).'</div>'
                        .'<div>'.nl2br($this->e((string) $value)).'</div>';
                }
            }
        }

        foreach ((array) Arr::get($body, 'tables', []) as $table) {
            $token = (string) ($table['token'] ?? '');
            $data = $tables[$token] ?? null;

            /*
             * Either shape counts as "has something to print": a flat list
             * of rows, or the grouped form a consolidated bill uses. Testing
             * only `rows` skipped every grouped table, which on an invoice
             * meant printing a total with no lines above it.
             */
            $hasRows = is_array($data)
                && (($data['rows'] ?? []) !== [] || ($data['groups'] ?? []) !== []);

            if (! $hasRows) {
                continue;
            }

            $out[] = $this->grid((string) ($table['title'] ?? ''), $data);
        }

        // An invoice's totals are the point of it, so they are printed whether
        // or not the items table rendered.
        if (isset($values['total']) && $values['total'] !== '') {
            $out[] = $this->totals($values, $receipt);
            $out[] = $this->settlement($values, $receipt);
        }

        if (Arr::get($body, 'show_clinical') && trim((string) ($values['advice'] ?? '')) !== '') {
            $out[] = '<div class="sec">Advice</div>'
                .'<div>'.nl2br($this->e((string) $values['advice'])).'</div>';
        }

        $notes = trim($this->fill((string) Arr::get($body, 'notes', ''), $values));

        if ($notes !== '') {
            $out[] = '<div class="block muted">'.nl2br($this->e($notes)).'</div>';
        }

        return implode("\n", $out);
    }

    /**
     * Who the document is about, and when — two columns in one tinted panel.
     *
     * @param  array<string, string>  $left
     * @param  array<string, string>  $right
     */
    private function panel(array $left, array $right, bool $receipt): string
    {
        $pairs = function (array $entries): string {
            $rows = '';

            foreach ($entries as $label => $value) {
                if (trim((string) $value) === '') {
                    continue;
                }

                $rows .= '<tr><td class="k">'.$this->e((string) $label).'</td>'
                    .'<td class="v">'.$this->e((string) $value).'</td></tr>';
            }

            return $rows === '' ? '' : '<table class="pair">'.$rows.'</table>';
        };

        $a = $pairs($left);
        $b = $pairs($right);

        if ($a === '' && $b === '') {
            return '';
        }

        // A till roll has one column; two would be four words wide.
        if ($receipt) {
            return '<div class="panel" style="padding:6px 8px">'.$a.$b.'</div>';
        }

        // One filled column takes the full width rather than leaving a hole
        // where the other would have been.
        if ($a === '' || $b === '') {
            return '<table class="panel"><tr><td>'.($a !== '' ? $a : $b).'</td></tr></table>';
        }

        return '<table class="panel"><tr>'
            .'<td class="col">'.$a.'</td>'
            .'<td class="col" style="border-left:1px solid #e5e7eb">'.$b.'</td>'
            .'</tr></table>';
    }

    /**
     * What the bill comes to.
     *
     * Right-aligned as a block of its own, with the grand total ruled off
     * above it and the outstanding balance — when there is one — in a tinted
     * band. The old version printed all six figures at the same weight in a
     * table nudged across with a margin, so the number somebody actually owed
     * looked exactly like the tax line above it.
     *
     * @param  array<string, string>  $values
     */
    private function totals(array $values, bool $receipt): string
    {
        $row = function (string $label, string $value, string $class = ''): string {
            if (trim($value) === '') {
                return '';
            }

            return '<tr'.($class !== '' ? ' class="'.$class.'"' : '').'>'
                .'<td class="k">'.$this->e($label).'</td>'
                .'<td class="v">'.$this->e($value).'</td></tr>';
        };

        $rows = $row('Subtotal', (string) ($values['subtotal'] ?? ''))
            .$row('Discount', (string) ($values['discount'] ?? ''))
            .$row('Tax', (string) ($values['tax'] ?? ''))
            .$row('Total', (string) ($values['total'] ?? ''), 'grand')
            .$row('Paid', (string) ($values['amount_paid'] ?? ''));

        /*
         * The balance is shown only when something is actually owed. A
         * settled bill printing "Balance ₹0.00" in a highlighted band invites
         * the one question the document exists to answer.
         */
        $balance = (string) ($values['balance'] ?? '');
        $owes = $balance !== '' && preg_replace('/[^0-9.]/', '', $balance) !== ''
            && (float) preg_replace('/[^0-9.]/', '', $balance) > 0;

        $rows .= $owes
            ? $row('Balance due', $balance, 'due')
            : $row('Balance', $balance);

        if (trim($rows) === '') {
            return '';
        }

        // Full width on a till roll; a right-hand block on a page.
        if ($receipt) {
            return '<table class="totals">'.$rows.'</table>';
        }

        return '<table><tr><td></td>'
            .'<td style="width:72mm"><table class="totals">'.$rows.'</table></td>'
            .'</tr></table>';
    }

    /**
     * How the bill was settled, and whether it was.
     *
     * The payment details on the left and the state on the right, because
     * they are read in that order: somebody checks the stamp first and only
     * looks at the method if they are reconciling. Absent entirely when
     * nothing has been paid — an empty "Payment details" box over a bill
     * somebody still owes is a box that answers nothing.
     *
     * @param  array<string, string>  $values
     */
    private function settlement(array $values, bool $receipt): string
    {
        $state = (string) ($values['payment_state'] ?? '');

        if ($state === '') {
            return '';
        }

        $rows = '';

        foreach ([
            'Method' => $values['payment_method'] ?? '',
            'Reference' => $values['payment_reference'] ?? '',
            'Paid on' => $values['payment_date'] ?? '',
        ] as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            $rows .= '<tr><td class="k">'.$this->e($label).'</td>'
                .'<td class="v">'.$this->e((string) $value).'</td></tr>';
        }

        $class = match ($state) {
            'PAID' => 'is-paid',
            'PART PAID' => 'is-part',
            default => 'is-unpaid',
        };

        $badge = '<div class="stamp-paid '.$class.'">'.$this->e($state).'</div>';

        // Nothing was tendered — the stamp is the whole message.
        if ($rows === '') {
            return '<div style="margin-top:9px">'.$badge.'</div>';
        }

        $details = '<div class="pay"><table>'.$rows.'</table></div>';

        if ($receipt) {
            return '<div style="margin-top:8px">'.$details.$badge.'</div>';
        }

        return '<table style="margin-top:7px"><tr>'
            .'<td style="width:62%;vertical-align:top;padding-right:4mm">'.$details.'</td>'
            .'<td style="vertical-align:top">'.$badge.'</td>'
            .'</tr></table>';
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function facts(array $pairs, bool $receipt, bool $alignRight = false): string
    {
        $rows = [];

        foreach ($pairs as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            $rows[] = '<tr><td class="k">'.$this->e((string) $label).'</td>'
                .'<td'.($alignRight ? ' class="num" style="text-align:right"' : '').'>'
                .$this->e((string) $value).'</td></tr>';
        }

        if ($rows === []) {
            return '';
        }

        // On a till roll everything stacks; there is no room for two columns.
        $width = $receipt ? '' : ' style="width:'.($alignRight ? '55%;margin-left:45%' : '100%').'"';

        return '<table class="facts"'.$width.'>'.implode('', $rows).'</table>';
    }

    /**
     * @param  array{columns: list<string>, rows: list<list<string>>, groups?: list<array{title: string, rows: list<list<string>>}>}  $data
     */
    private function grid(string $title, array $data): string
    {
        $head = '';
        $columns = $data['columns'] ?? [];

        foreach ($columns as $index => $column) {
            /*
             * The last column is the money and the first is a row number;
             * both read better hard against their edge than centred in a
             * column sized for its heading.
             */
            $class = $index === array_key_last($columns) ? ' class="num"' : '';
            $head .= '<th'.$class.'>'.$this->e((string) $column).'</th>';
        }

        $span = count($columns);
        $body = '';
        $index = 0;

        $rowHtml = function (array $row) use (&$index, $columns): string {
            $cells = '';

            foreach ($row as $column => $cell) {
                // Numbers right, words left — decided by position rather than
                // by sniffing the value, so "10 mg" does not jump columns.
                $numeric = $column > 2
                    && is_numeric(str_replace(['₹', ',', ' ', '%'], '', (string) $cell));

                $cells .= '<td'.($numeric ? ' class="num"' : '').'>'.$this->e((string) $cell).'</td>';
            }

            $html = '<tr'.($index % 2 === 1 ? ' class="alt"' : '').'>'.$cells.'</tr>';
            $index++;

            return $html;
        };

        /*
         * GROUPED, where the payload said how.
         *
         * A consolidated bill carries a consultation, a test and a strip of
         * tablets; a flat list of them reads as a shopping receipt. The
         * heading rows are what let a patient check "was I charged for the
         * lab?" without reading every line.
         */
        if (($data['groups'] ?? []) !== []) {
            foreach ($data['groups'] as $group) {
                $body .= '<tr class="grp"><td colspan="'.$span.'">'
                    .$this->e((string) ($group['title'] ?? '')).'</td></tr>';

                // Zebra restarts per group, so the first row under every
                // heading reads the same way.
                $index = 0;

                foreach ($group['rows'] ?? [] as $row) {
                    $body .= $rowHtml($row);
                }
            }
        } else {
            foreach (array_values($data['rows'] ?? []) as $row) {
                $body .= $rowHtml($row);
            }
        }

        $caption = $title !== '' ? '<div class="sec">'.$this->e($title).'</div>' : '';

        return $caption.'<table class="grid"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function footer(array $config, array $values, bool $receipt): string
    {
        $footer = Arr::get($config, 'footer', []);
        $out = [];

        if (Arr::get($footer, 'show_signature') && ! $receipt) {
            $out[] = '<table class="sign"><tr><td></td><td style="width:58mm">'
                .'<div class="line muted">'.$this->e((string) Arr::get($footer, 'signature_label', ''))
                .'</div></td></tr></table>';
        }

        /*
         * Terms get a box of their own, separate from the footer lines.
         *
         * They are the one part of a document somebody may have to point at
         * later — a refund policy, a validity period — and running them into
         * the same centred grey paragraph as "Thank you" is how a clinic
         * loses that argument.
         */
        $terms = trim($this->fill((string) Arr::get($footer, 'terms', ''), $values));

        if ($terms !== '') {
            $out[] = '<div class="terms">'.nl2br($this->e($terms)).'</div>';
        }

        $lines = [];

        foreach ((array) Arr::get($footer, 'lines', []) as $line) {
            $filled = trim($this->fill((string) $line, $values));

            if ($filled !== '') {
                $lines[] = $this->e($filled);
            }
        }

        if ($lines !== []) {
            $out[] = '<div class="foot muted" style="text-align:center">'.implode('<br>', $lines).'</div>';
        }

        /*
         * The provenance line, always. A printed medical document that cannot
         * say when it was produced or from which version of the template is a
         * document nobody can place afterwards.
         */
        $stamp = array_filter([
            $values['document_number'] ?? '',
            ($values['generated_on'] ?? '') !== '' ? 'Printed '.$values['generated_on'] : '',
            ($values['generated_by'] ?? '') !== '' ? 'by '.$values['generated_by'] : '',
        ], fn ($part) => trim((string) $part) !== '');

        $out[] = '<div class="stamp">'.$this->e(implode(' · ', $stamp)).'</div>';

        return implode("\n", $out);
    }

    /**
     * Substitute `{{token}}`, tolerating whitespace inside the braces.
     *
     * An UNKNOWN placeholder becomes an empty string rather than staying as
     * literal `{{whatever}}`. Templates are validated before they can be
     * activated, so reaching here means a token the payload simply had no
     * value for — an unfilled optional. Printing the braces would put
     * `{{patient_email}}` on a document handed to a patient.
     */
    private function fill(?string $text, array $values): string
    {
        // A config read back from jsonb can hold a null where the defaults
        // have an empty string — an unset footer line, a blank registration
        // number. Null is "nothing to print", not a failure.
        if ($text === null) {
            return '';
        }

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $match) => (string) ($values[mb_strtolower($match[1])] ?? ''),
            $text,
        ) ?? '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A colour from a config is a value somebody sent; only hex is honoured. */
    private function colour(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value)
            ? $value
            : '#2e37a4';
    }

    private function scale(float $size, float $factor): string
    {
        return (string) round($size * $factor, 1);
    }
}
