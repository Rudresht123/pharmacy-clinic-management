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
        $family = Arr::get($layout, 'font_family') === 'serif' ? 'serif' : 'sans-serif';

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

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <style>
            {$page}
            body { font-family: {$family}; font-size: {$size}pt; color: #111; margin: 0; }
            table { width: 100%; border-collapse: collapse; }
            .accent { color: {$accent}; }
            .rule { border-bottom: 1.5px solid {$accent}; height: 0; margin: 6px 0 10px; }
            .doc-title { font-size: {$this->scale($size, 1.35)}pt; font-weight: bold; }
            .muted { color: #555; font-size: {$this->scale($size, 0.85)}pt; }
            .lines td { padding: 1px 0; }
            .facts { margin: 10px 0; }
            .facts td { padding: 2px 6px 2px 0; vertical-align: top; }
            .facts .k { color: #555; width: 26%; }
            .grid { margin-top: 10px; }
            .grid th { background: #f2f3f7; border: 1px solid #d8dae5; padding: 5px 6px;
                       text-align: left; font-size: {$this->scale($size, 0.9)}pt; }
            .grid td { border: 1px solid #d8dae5; padding: 5px 6px; vertical-align: top; }
            .grid .num { text-align: right; }
            .block { margin-top: 10px; }
            .block .h { font-weight: bold; font-size: {$this->scale($size, 0.95)}pt; }
            .sign { margin-top: 28px; }
            .sign .line { border-top: 1px solid #555; width: 55mm; padding-top: 3px; }
            .foot { margin-top: 14px; }
        </style></head><body>
        {$content}
        </body></html>
        HTML;
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
            ? '<img src="'.$logoData.'" style="max-height:18mm;max-width:45mm;">'
            : '';

        $position = Arr::get($header, 'logo_position', 'left');
        $rule = Arr::get($header, 'show_divider') ? '<div class="rule"></div>' : '';

        $identity = '<div class="doc-title accent">'.$this->e($legal !== '' ? $legal : $title).'</div>'
            .($legal !== '' && $title !== '' ? '<div class="muted">'.$this->e($title).'</div>' : '')
            .($registration !== '' ? '<div class="muted">'.$this->e($registration).'</div>' : '')
            .'<table class="lines muted">'.implode('', $lines).'</table>';

        if ($receipt || $position === 'center') {
            return '<div style="text-align:center">'.$logo.$identity.'</div>'.$rule;
        }

        [$left, $right] = $position === 'right'
            ? [$identity, $logo]
            : [$logo, $identity];

        return '<table><tr>'
            .'<td style="width:45mm;vertical-align:top">'.$left.'</td>'
            .'<td style="vertical-align:top;'.($position === 'right' ? '' : 'padding-left:6mm').'">'.$right.'</td>'
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

        if (Arr::get($body, 'show_patient')) {
            $out[] = $this->facts([
                'Patient' => $values['patient_name'] ?? '',
                'Patient ID' => $values['patient_id'] ?? '',
                'Age / Sex' => trim(($values['patient_age'] ?? '').' / '.($values['patient_gender'] ?? ''), ' /'),
                'Mobile' => $values['patient_mobile'] ?? '',
            ], $receipt);
        }

        if (Arr::get($body, 'show_visit')) {
            $out[] = $this->facts([
                'Doctor' => $values['doctor_name'] ?? '',
                'Reg. no.' => $values['doctor_registration_number'] ?? '',
                'Visit date' => $values['visit_date'] ?? '',
            ], $receipt);
        }

        if (Arr::get($body, 'show_clinical')) {
            foreach ([
                'Complaint' => $values['symptoms'] ?? '',
                'Diagnosis' => $values['diagnosis'] ?? '',
                'Vitals' => $values['vitals'] ?? '',
            ] as $label => $value) {
                if (trim((string) $value) !== '') {
                    $out[] = '<div class="block"><span class="h">'.$this->e($label).'</span><br>'
                        .nl2br($this->e((string) $value)).'</div>';
                }
            }
        }

        foreach ((array) Arr::get($body, 'tables', []) as $table) {
            $token = (string) ($table['token'] ?? '');
            $data = $tables[$token] ?? null;

            if (! is_array($data) || ($data['rows'] ?? []) === []) {
                continue;
            }

            $out[] = $this->grid((string) ($table['title'] ?? ''), $data);
        }

        // An invoice's totals are the point of it, so they are printed whether
        // or not the items table rendered.
        if (isset($values['total']) && $values['total'] !== '') {
            $out[] = $this->facts(array_filter([
                'Subtotal' => $values['subtotal'] ?? '',
                'Discount' => $values['discount'] ?? '',
                'GST' => $values['tax'] ?? '',
                'Total' => $values['total'] ?? '',
                'Paid' => $values['amount_paid'] ?? '',
                'Balance' => $values['balance'] ?? '',
            ], fn ($value) => trim((string) $value) !== ''), $receipt, true);
        }

        if (Arr::get($body, 'show_clinical') && trim((string) ($values['advice'] ?? '')) !== '') {
            $out[] = '<div class="block"><span class="h">Advice</span><br>'
                .nl2br($this->e((string) $values['advice'])).'</div>';
        }

        $notes = trim($this->fill((string) Arr::get($body, 'notes', ''), $values));

        if ($notes !== '') {
            $out[] = '<div class="block muted">'.nl2br($this->e($notes)).'</div>';
        }

        return implode("\n", $out);
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

    /** @param array{columns: list<string>, rows: list<list<string>>} $data */
    private function grid(string $title, array $data): string
    {
        $head = '';

        foreach ($data['columns'] ?? [] as $column) {
            $head .= '<th>'.$this->e((string) $column).'</th>';
        }

        $body = '';

        foreach ($data['rows'] ?? [] as $row) {
            $cells = '';

            foreach ($row as $index => $cell) {
                // Numbers right, words left — decided by position rather than
                // by sniffing the value, so "10 mg" does not jump columns.
                $numeric = $index > 1 && is_numeric(str_replace(['₹', ',', ' '], '', (string) $cell));
                $cells .= '<td'.($numeric ? ' class="num"' : '').'>'.$this->e((string) $cell).'</td>';
            }

            $body .= '<tr>'.$cells.'</tr>';
        }

        $caption = $title !== ''
            ? '<div class="block"><span class="h">'.$this->e($title).'</span></div>'
            : '';

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
            $out[] = '<table class="sign"><tr><td></td><td style="width:60mm">'
                .'<div class="line muted">'.$this->e((string) Arr::get($footer, 'signature_label', ''))
                .'</div></td></tr></table>';
        }

        $lines = [];

        foreach ((array) Arr::get($footer, 'lines', []) as $line) {
            $filled = trim($this->fill((string) $line, $values));

            if ($filled !== '') {
                $lines[] = $this->e($filled);
            }
        }

        $terms = trim($this->fill((string) Arr::get($footer, 'terms', ''), $values));

        if ($terms !== '') {
            $lines[] = $this->e($terms);
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

        $out[] = '<div class="foot muted" style="text-align:center">'
            .$this->e(implode(' · ', $stamp)).'</div>';

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
    private function fill(string $text, array $values): string
    {
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
