<?php

namespace App\Services\Documents;

use App\Support\Documents\DocumentTypes;
use Illuminate\Support\Arr;

/**
 * A template's config plus a document's data, as one HTML page for dompdf.
 *
 * Deliberately separate from the PDF library: this class knows what a bill
 * looks like, PdfRenderer knows how to turn HTML into a PDF.
 *
 * TABLES, NOT FLEX. dompdf has no flexbox and no grid, so every layout here is
 * a table, and anything that is not a solid colour — the corner swoosh, the
 * footer waves, the stand-in logo — is a GD image from Gradient.
 *
 * A sheet, top to bottom:
 *
 *     letterhead     the clinic's mark and name; contacts on the right
 *     title row      what the document is; its number and date in a box
 *     people         the patient, and the doctor beside them
 *     body           clinical notes, the items table
 *     lower grid     summary and notes on the left; payment and QR on the right
 *     signatures
 *     footer band    fixed to the foot of every page
 *
 * A till roll (80mm) has no room for any of that and gets its own single
 * column — see receiptPage().
 *
 * Every value is escaped: a patient's name and a doctor's notes are free text
 * that ends up inside markup.
 */
class DocumentHtml
{
    /** The round badge beside the title, per document type. */
    private const TYPE_ICON = [
        'patient_registration' => 'user-plus',
        'prescription' => 'prescription',
        'consultation_summary' => 'clipboard-text',
        'clinic_invoice' => 'receipt',
        'pharmacy_invoice' => 'pill',
        'payment_receipt' => 'cash',
        'clinic_receipt' => 'cash',
        'clinic_refund' => 'cash',
        'document_cover' => 'file-stack',
    ];

    /** Printed under the title when the template gives no subtitle of its own. */
    private const SUBTITLE = [
        'patient_registration' => 'Your health journey starts here',
        'prescription' => 'Medicines and advice from your doctor',
        'consultation_summary' => 'Findings, diagnosis and advice',
        'clinic_invoice' => 'Consultation, tests, pharmacy & services',
        'clinic_receipt' => 'Payment received with thanks',
        'clinic_refund' => 'Amount returned to you',
        'pharmacy_invoice' => 'Medicines for a healthier tomorrow',
        'payment_receipt' => 'Payment received with thanks',
        'document_cover' => 'Documents on this patient’s file',
    ];

    /**
     * Who handed the document over — the left-hand signature. A pharmacy
     * dispenses, a counter receives.
     */
    private const HANDOVER = [
        'pharmacy_invoice' => 'Dispensed by',
        'payment_receipt' => 'Received by',
        'clinic_invoice' => 'Received by',
        'clinic_receipt' => 'Received by',
        'clinic_refund' => 'Refunded by',
    ];

    /** The three badges along the footer band, by kind of document. */
    private const BADGES = [
        'care' => [
            ['shield-check', 'Trusted Care', 'For Every Family'],
            ['stethoscope', 'Experienced Doctors', 'Quality Healthcare'],
            ['building-hospital', 'Modern Facilities', 'Advanced Technology'],
        ],
        'pharmacy' => [
            ['shield-check', 'Safe Medicines', 'Authentic & Quality'],
            ['user', 'Qualified Pharmacists', 'Expert Guidance'],
            ['heart-handshake', 'Better Health', 'Our Priority'],
        ],
        'billing' => [
            ['award', 'Quality Care', 'Our Commitment'],
            ['microscope', 'Advanced Technology', 'Better Diagnosis'],
            ['users', 'Healthy Community', 'Stronger Together'],
        ],
    ];

    /** The small picture against a charge, from the category it was billed under. */
    private const ROW_ICON = [
        'registration' => 'file-text',
        'consultation' => 'stethoscope',
        'laboratory' => 'flask',
        'lab' => 'flask',
        'pharmacy' => 'pill',
        'procedure' => 'report-medical',
    ];

    /** Fixed companions to the accent, whatever accent a branch picks. */
    private const FRESH = '#14a594';

    private const MINT = '#dcf3ea';

    private const MINT_INK = '#0f766e';

    public function __construct(
        private readonly QrCode $qr = new QrCode,
        private readonly Gradient $gradient = new Gradient,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array{values: array<string, string>, tables: array<string, mixed>}  $payload
     */
    public function render(string $documentType, array $config, array $payload, ?string $logoData = null): string
    {
        $values = $payload['values'] ?? [];
        $tables = $payload['tables'] ?? [];
        $layout = Arr::get($config, 'layout', []);

        if (Arr::get($layout, 'paper') === DocumentTypes::PAPER_RECEIPT) {
            return $this->receiptPage($documentType, $config, $values, $tables);
        }

        $a4 = Arr::get($layout, 'paper', DocumentTypes::PAPER_A4) === DocumentTypes::PAPER_A4;
        $accent = $this->colour(Arr::get($layout, 'accent', '#0b5275'));

        $content = implode("\n", array_filter([
            $this->letterhead($config, $values, $logoData, $accent),
            $this->titleRow($documentType, $config, $values),
            $this->intro($config, $values),
            $documentType === 'patient_registration'
                ? $this->registration($config, $values)
                : $this->people($documentType, $config, $values),
            $this->clinical($config, $values),
            $this->itemTables($documentType, $config, $tables),
            $this->lowerGrid($documentType, $config, $values),
            $this->smallPrint($config, $values),
            $this->closing($documentType, $config, $values),
        ]));

        // The fixed band and the corner art come first: dompdf repeats a fixed
        // element on every page from the one it is declared on.
        $art = $this->footerBand($documentType, $config, $values, $accent, $a4)
            .$this->corner($a4);

        return $this->page($layout, $art.$content);
    }

    /* ==================================================================
       The page and its stylesheet
       ================================================================== */

    /** @param array<string, mixed> $layout */
    private function page(array $layout, string $content): string
    {
        $paper = Arr::get($layout, 'paper', DocumentTypes::PAPER_A4);
        $a4 = $paper === DocumentTypes::PAPER_A4;
        $margins = Arr::get($layout, 'margin_mm', []);
        $accent = $this->colour(Arr::get($layout, 'accent', '#0b5275'));
        $size = (float) (Arr::get($layout, 'font_size', 11) ?: 11);

        // Half the paper of A4: the type steps down with it, or a
        // prescription's second half runs onto a second slip.
        if (! $a4) {
            $size = round($size * 0.86, 2);
        }

        /*
         * DejaVu, not the CSS generic families: `sans-serif` resolves to a
         * Latin-1 core font, and the rupee sign is not in Latin-1.
         */
        $serif = Arr::get($layout, 'font_family') === 'serif';
        $family = $serif ? "'DejaVu Serif', serif" : Fonts::SANS.", 'DejaVu Sans', sans-serif";
        $heavy = $serif ? $family : Fonts::SANS_HEAVY.", 'DejaVu Sans', sans-serif";
        $medium = $serif ? $family : Fonts::SANS_MEDIUM.", 'DejaVu Sans', sans-serif";
        $script = Fonts::SCRIPT.", 'DejaVu Serif', serif";

        $top = (float) ($margins['top'] ?? 14);
        $left = (float) ($margins['left'] ?? 12);
        $right = (float) ($margins['right'] ?? 12);
        $band = $this->bandHeight($a4);
        // The band sits in the bottom margin, so the margin is the band plus air.
        $bottom = $band + ($a4 ? 3.5 : 2);

        // Every shade is mixed here as plain hex; dompdf's colour functions
        // are not something a printed bill should depend on.
        $ink = $this->darken($accent, 0.58);
        $deep = $this->darken($accent, 0.18);
        $softInk = $this->mix($accent, 0.28);
        $rule = $this->mix($accent, 0.80);
        $soft = $this->mix($accent, 0.84);
        $tint = $this->mix($accent, 0.90);
        $pale = $this->mix($accent, 0.955);
        $headLine = $this->mix($accent, 0.22);
        $mint = self::MINT;
        $mintInk = $this->darken(self::MINT_INK, 0.1);

        $fs = fn (float $factor) => round($size * $factor, 1).'pt';

        $display = $fs($a4 ? 1.55 : 1.3);
        $title = $fs($a4 ? 1.5 : 1.32);
        $lead = $fs(1.28);
        $body = $fs(1.0);
        $small = $fs(0.9);
        $micro = $fs(0.78);
        $nano = $fs(0.68);
        $thanks = $a4 ? '19pt' : '13pt';
        $badgeT = $a4 ? '7.4pt' : '6.2pt';
        $badgeS = $a4 ? '6.2pt' : '5.2pt';
        $longTitle = $fs($a4 ? 1.35 : 1.2);
        $numSize = $fs($a4 ? 1.12 : 1.02);
        $nameSize = $fs(1.05);
        $kvSize = $fs(0.84);
        $grandSize = $fs(1.12);
        $panelPad = $a4 ? '1.5mm 2.4mm' : '1.2mm 2mm';
        $peoplePad = $a4 ? '1.8mm 0' : '1.4mm 0';
        $overline = $a4 ? '6.3pt' : '5.6pt';
        $tiny = $fs(0.6);
        // The letterhead rises into the top margin, which the swoosh already dresses.
        $lift = $a4 ? '-4mm' : '-3mm';

        $gap = $a4 ? '2.4mm' : '1.9mm';
        $thPad = $a4 ? '2mm 2.2mm' : '1.5mm 1.5mm';
        $tdPad = $a4 ? '1.1mm 2.2mm' : '0.9mm 1.5mm';

        /*
         * A glyph in a circle, centred by padding rather than line-height:
         * dompdf seats an icon glyph at the top or the bottom of a tall line
         * box depending on what the line-height is, never in its middle.
         */
        $round = function (float $box, float $factor) use ($size): string {
            $icon = round($size * $factor, 1);
            $top = max(0, round(($box - $icon * 0.3528) / 2, 2));

            return "width: {$box}mm; height: ".round($box - $top, 2)."mm; padding-top: {$top}mm; "
                ."line-height: 1; font-size: {$icon}pt;";
        };

        $badgeBox = $round($a4 ? 8.5 : 7, 1.15);
        $avatarBox = $round($a4 ? 7.5 : 6.2, 1.25);
        $tileBox = $round($a4 ? 5.2 : 4.6, 0.82);
        $secBox = $round(8, 1.05);
        $footIcon = $a4 ? 9.0 : 7.0;
        $footSide = $a4 ? 6.8 : 5.4;
        $footTop = round(($footSide - $footIcon * 0.3528) / 2, 2);
        $footBox = "width: {$footSide}mm; height: ".round($footSide - $footTop, 2)."mm; padding-top: {$footTop}mm; "
            ."line-height: 1; font-size: {$footIcon}pt;";
        $avCol = $a4 ? '10mm' : '8.2mm';
        $signRoom = $a4 ? '5mm' : '3.5mm';

        $width = $a4 ? 210 : 148;
        $iconFamily = Icons::FAMILY;

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <style>
            @page { margin: {$top}mm {$right}mm {$bottom}mm {$left}mm; }
            body { font-family: {$family}; font-size: {$body}; color: #1e293b; margin: 0; line-height: 1.22; }
            a { color: inherit; text-decoration: none; }
            table { width: 100%; border-collapse: collapse; }
            td, th { vertical-align: top; padding: 0; }
            .ti { font-family: {$iconFamily}; font-style: normal; font-weight: normal; }
            .muted { color: #64748b; }
            .gap { margin-top: {$gap}; }

            /* ---- art -------------------------------------------------- */
            .corner { position: absolute; top: -{$top}mm; left: -{$left}mm; }
            .fband { position: fixed; left: -{$left}mm; bottom: -{$bottom}mm; width: {$width}mm;
                     height: {$band}mm; }
            .fband td { vertical-align: middle; }
            .thanks { font-family: {$script}; font-size: {$thanks}; color: {$ink}; text-align: center; }
            .thanks .heart { font-family: 'DejaVu Sans', sans-serif; font-style: normal; color: #e11d48; }
            .b-ic { {$footBox} border-radius: 50%; background-color: {$accent}; color: #ffffff; text-align: center; }
            .b-t { font-family: {$medium}; font-size: {$badgeT}; color: {$accent}; padding-left: 1.8mm; line-height: 1.15; }
            .b-s { font-size: {$badgeS}; color: #64748b; padding-left: 1.8mm; line-height: 1.15; }

            /* ---- letterhead ------------------------------------------- */
            .lh { margin-top: {$lift}; }
            .lh td { vertical-align: middle; }
            .org { font-family: {$heavy}; font-size: {$display}; color: {$ink}; line-height: 1.1; }
            .tagline { font-size: {$nano}; color: #5b7487; margin-top: 0.3mm; }
            .dept { font-family: {$medium}; font-size: {$nano}; color: {$accent}; margin-top: 0.2mm; }
            .reg { font-size: {$nano}; color: #64748b; }
            .contact td { padding: 0.25mm 0; font-size: {$nano}; color: #334155; vertical-align: middle; }
            .contact .ic { width: 4mm; color: {$accent}; font-size: {$micro}; }
            .rule { border-top: 0.8pt solid {$rule}; margin-top: 2mm; }

            /* ---- title row -------------------------------------------- */
            .title-row td { vertical-align: middle; }
            .t-badge { {$badgeBox} border-radius: 50%; background-color: {$accent}; color: #ffffff; text-align: center; }
            .doc-title { font-family: {$heavy}; font-size: {$title}; color: {$ink}; line-height: 1.15; }
            .doc-title.long { font-size: {$longTitle}; }
            .doc-sub { font-size: {$micro}; color: {$softInk}; margin-top: 0.4mm; }
            .meta { background-color: {$tint}; border-radius: 7px; padding: 1.4mm 3mm; }
            .meta td { padding: 0.25mm 0; vertical-align: middle; white-space: nowrap; }
            .meta .k { font-family: {$medium}; font-size: {$overline}; letter-spacing: 0.06em; text-transform: uppercase;
                       color: {$accent}; padding-right: 5mm; }
            .meta .v { font-size: {$nano}; color: #334155; text-align: right; }
            .meta .v.lead { font-family: {$heavy}; font-size: {$numSize}; color: {$deep}; }

            /* ---- people ----------------------------------------------- */
            .people { background-color: {$pale}; border-radius: 8px; padding: {$peoplePad}; margin-top: {$gap}; }
            .people td { vertical-align: middle; }
            .people .pc { padding: 0 3.4mm; }
            .people .pc.split { border-left: 0.8pt solid {$rule}; }
            .avatar { {$avatarBox} border-radius: 50%; background-color: {$soft}; color: {$accent}; text-align: center; }
            .avatar.light { background-color: #ffffff; }
            .avatar.pale { background-color: {$tint}; }
            .av-col { width: {$avCol}; }
            .ov { font-family: {$medium}; font-size: {$overline}; letter-spacing: 0.08em; text-transform: uppercase;
                  color: {$accent}; }
            .p-name { font-size: {$nameSize}; font-weight: bold; color: {$ink}; margin-top: 0.1mm; line-height: 1.2; }
            .p-id { font-size: {$nano}; font-weight: normal; color: #64748b; }
            .p-sub { font-size: {$nano}; color: #475569; margin-top: 0.3mm; line-height: 1.2; }
            .p-sub .sep { color: #94a3b8; }

            /* ---- registration sections -------------------------------- */
            .sec { background-color: {$tint}; border-radius: 12px; margin-top: {$gap}; }
            .sec td { vertical-align: middle; }
            .sec-badge { {$secBox} border-radius: 50%; background-color: {$accent}; color: #ffffff; text-align: center; }
            .sec-title { font-size: {$body}; font-weight: bold; color: {$accent}; padding-left: 3mm; }
            .sec-body { padding: 2.4mm 1mm 0.4mm; }
            .sec-body td { vertical-align: middle; }
            .fld { padding: 0 3mm; border-left: 0.6pt solid #e2e8f0; }
            .fld.first { border-left: 0; }
            .fld .l { font-size: {$micro}; color: #64748b; }
            .fld .v { font-size: {$body}; font-weight: bold; color: {$ink}; margin-top: 0.6mm; }
            .fld .v .ti { color: {$accent}; }
            .clin .v { font-size: {$small}; font-weight: normal; color: #1e293b; margin-top: 0.3mm; }
            .subgrid { border: 0.6pt solid #e2e8f0; border-radius: 8px; padding: 2mm 0; margin-top: 2mm; }
            .consent { background-color: #e6f6f0; border-radius: 10px; padding: 3.4mm 4mm; margin-top: {$gap}; }
            .consent .shield { color: #0d9488; font-size: {$title}; }
            .consent .h { font-size: {$lead}; font-weight: bold; color: {$mintInk}; }
            .consent .txt { font-size: {$small}; color: #334155; margin-top: 1mm; line-height: 1.4; }
            .sign-room { height: {$signRoom}; }
            .sign-line { border-top: 0.8pt solid #94a3b8; padding-top: 1mm; font-size: {$micro};
                         color: #475569; text-align: center; }

            /* ---- cards ------------------------------------------------ */
            .card { background-color: {$pale}; border-radius: 8px; margin-top: {$gap}; }
            .card.white { background-color: #ffffff; border: 0.6pt solid #e2e8f0; }
            .card.keep { page-break-inside: avoid; }
            .card-h { background-color: {$tint}; border-radius: 8px 8px 0 0; padding: 1.1mm 2.6mm; }
            .card-h td { vertical-align: middle; }
            .tile { {$tileBox} border-radius: 1.6mm; background-color: {$accent}; color: #ffffff; text-align: center; }
            .card-t { font-size: {$small}; font-weight: bold; color: {$accent}; padding-left: 2mm; }
            .card-b { padding: {$panelPad}; }
            .note { font-size: {$small}; color: #334155; line-height: 1.4; }
            .steps td { font-size: {$small}; color: #334155; padding: 0.4mm 0; line-height: 1.3; }
            .steps .n { width: 5mm; color: {$accent}; font-weight: bold; }

            /* ---- the items table -------------------------------------- */
            .sect-h { margin-top: {$gap}; font-size: {$body}; font-weight: bold; color: {$accent}; }
            .items { border-collapse: separate; border-spacing: 0; margin-top: {$gap}; }
            .sect-h + .items { margin-top: 1.6mm; }
            .items th { background-color: {$accent}; color: #ffffff; font-size: {$micro}; font-weight: bold;
                        text-align: left; padding: {$thPad}; border-right: 0.6pt solid {$headLine};
                        vertical-align: middle; }
            .items th.first { border-top-left-radius: 7px; }
            .items th.last { border-top-right-radius: 7px; border-right: 0; }
            .items td { font-size: {$small}; color: #1e293b; padding: {$tdPad}; vertical-align: middle;
                        border-bottom: 0.6pt solid #e5eaf0; border-right: 0.6pt solid #e5eaf0; }
            .items td.first { border-left: 0.6pt solid #e5eaf0; }
            .items .num { text-align: right; white-space: nowrap; }
            .items .mid { text-align: center; }
            .items .strong { font-weight: bold; color: {$ink}; }
            .items .ico { color: {$accent}; font-size: {$body}; padding-right: 1.6mm; }

            /* ---- summary and payment ---------------------------------- */
            .sum td { font-size: {$kvSize}; color: #334155; padding: 0.8mm 2.6mm; border-bottom: 0.6pt solid #e5eaf0;
                      vertical-align: middle; }
            .sum .v { text-align: right; font-weight: bold; color: {$ink}; white-space: nowrap; }
            .sum tr.last td { border-bottom: 0; }
            .grand { background-color: {$mint}; border-radius: 0 0 8px 8px; padding: 1.5mm 2.6mm; }
            .grand td { vertical-align: middle; font-size: {$grandSize}; font-weight: bold; color: {$ink}; }
            .grand .v { text-align: right; white-space: nowrap; }
            .words { font-size: {$nano}; font-style: italic; color: #475569; margin-top: 0.8mm; }
            .kv td { font-size: {$kvSize}; padding: 0.3mm 0; vertical-align: middle; }
            .kv .k { color: #64748b; width: 1%; white-space: nowrap; padding-right: 4mm; }
            .kv .v { color: {$ink}; }
            .kv .money { font-weight: bold; }
            .kv .owe { color: #dc2626; font-weight: bold; }
            .pill { padding: 0.5mm 2.6mm; border-radius: 9px; font-size: {$micro}; font-weight: bold; }
            .pill.paid { background-color: #d8f3e4; color: #15803d; }
            .pill.part { background-color: #fdeac4; color: #b45309; }
            .pill.unpaid { background-color: #fde2e2; color: #b91c1c; }
            .pill.refund { background-color: {$tint}; color: {$accent}; }
            .qr-box { text-align: center; }
            .qr-t { font-family: {$medium}; font-size: {$nano}; color: {$accent}; margin-top: 0.5mm; }
            .qr-n { font-size: {$tiny}; color: #475569; }

            /* ---- signatures and small print --------------------------- */
            .signs { margin-top: 1.5mm; page-break-inside: avoid; }
            .signs td { vertical-align: bottom; }
                        .s-label { font-family: {$medium}; font-size: {$micro}; color: #64748b; }
            .s-name { font-size: {$body}; font-weight: bold; color: {$ink}; }
            .s-role { font-size: {$micro}; color: #64748b; }
            .smallprint { margin-top: {$gap}; text-align: center; font-size: {$micro}; color: #475569; }
            .provenance { text-align: center; font-size: {$tiny}; color: #7b8aa0; line-height: 1; }
            .intro { margin-top: {$gap}; font-size: {$small}; color: #334155; line-height: 1.4; }
        </style></head><body>
        {$content}
        </body></html>
        HTML;
    }

    /** The footer band, including the provenance line along its foot. */
    private function bandHeight(bool $a4): float
    {
        return $a4 ? 25.0 : 18.5;
    }

    /* ==================================================================
       Art
       ================================================================== */

    private function corner(bool $a4): string
    {
        $art = $this->gradient->swoosh(self::FRESH, $this->mix(self::FRESH, 0.70));

        return $art === null
            ? ''
            : '<div class="corner"><img src="'.$art.'" style="'.($a4 ? 'width:58mm;height:23mm' : 'width:42mm;height:17mm').'"></div>';
    }

    /**
     * The band fixed to the foot of every page: a thank-you line and three
     * badges over a pale ground with a wave in each corner.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function footerBand(string $documentType, array $config, array $values, string $accent, bool $a4): string
    {
        $lines = $this->footerLines($config, $values);
        $headline = rtrim($lines[0] ?? '', '. ');

        $base = $this->mix($accent, 0.965);
        $art = $this->gradient->footerWaves(
            $base,
            $this->mix($accent, 0.90),
            $accent,
            self::FRESH,
            $this->mix($accent, 0.62),
        );

        $ground = 'background-color:'.$base.';'
            .($art !== null ? 'background-image:url('.$art.');background-size:100% 100%;background-repeat:no-repeat;' : '');

        $badges = '';

        foreach (self::BADGES[$this->badgeSet($documentType)] as [$icon, $label, $sub]) {
            $badges .= '<td style="width:33%"><table><tr>'
                .'<td style="width:'.($a4 ? '6.8mm' : '5.4mm').'"><div class="b-ic">'.Icons::span($icon).'</div></td>'
                .'<td><div class="b-t">'.$this->e($label).'</div><div class="b-s">'.$this->e($sub).'</div></td>'
                .'</tr></table></td>';
        }

        // Three layers placed outright rather than stacked in table rows,
        // which grow past any height they are given: the thank-you between
        // the waves (they rise above the upper half, see Gradient), the badges
        // on the swell below, and the stamp along the very foot.
        $side = $a4 ? '18mm' : '14mm';

        return '<div class="fband" style="'.$ground.'">'
            .'<div class="thanks" style="position:absolute;left:'.$side.';right:'.$side.';top:'.($a4 ? '1.2mm' : '0.2mm').'">'
            .($headline !== '' ? $this->e($headline).' <span class="heart">&#9829;</span>' : '')
            .'</div>'
            .'<div style="position:absolute;left:'.($a4 ? '12mm' : '7mm').';right:'.($a4 ? '12mm' : '7mm').';bottom:'.($a4 ? '4mm' : '2.8mm').'">'
            .'<table><tr>'.$badges.'</tr></table></div>'
            .'<div style="position:absolute;left:0;right:0;bottom:'.($a4 ? '0.8mm' : '0.3mm').'">'.$this->provenance($values).'</div>'
            .'</div>';
    }

    private function badgeSet(string $documentType): string
    {
        return match ($documentType) {
            'patient_registration', 'prescription' => 'care',
            'pharmacy_invoice', 'payment_receipt' => 'pharmacy',
            default => 'billing',
        };
    }

    /* ==================================================================
       Letterhead and title
       ================================================================== */

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function letterhead(array $config, array $values, ?string $logoData, string $accent): string
    {
        $header = Arr::get($config, 'header', []);

        $legal = $this->fill(Arr::get($header, 'legal_name', ''), $values);
        $department = $this->fill(Arr::get($header, 'department', ''), $values);
        $tagline = $this->fill(Arr::get($header, 'tagline', ''), $values);
        $registration = $this->fill(Arr::get($header, 'registration_no', ''), $values);
        $name = $legal !== '' ? $legal : $this->fill(Arr::get($header, 'title', ''), $values);

        $identity = '<div class="org">'.$this->e($name).'</div>'
            .($tagline !== '' ? '<div class="tagline">'.$this->e($tagline).'</div>' : '')
            .($department !== '' ? '<div class="dept">'.$this->e($department).'</div>' : '')
            .($registration !== '' ? '<div class="reg">'.$this->e($registration).'</div>' : '');

        $a4 = Arr::get($config, 'layout.paper', DocumentTypes::PAPER_A4) === DocumentTypes::PAPER_A4;
        $logo = '';

        if (Arr::get($header, 'show_logo')) {
            $mark = $logoData ?? $this->gradient->mark($accent, self::FRESH);

            $logo = match (true) {
                $logoData !== null => '<img src="'.$logoData.'" style="'.($a4 ? 'max-height:12mm;max-width:30mm' : 'max-height:10mm;max-width:24mm').'">',
                $mark !== null => '<img src="'.$mark.'" style="'.($a4 ? 'width:11.5mm;height:11.5mm' : 'width:9mm;height:9mm').'">',
                default => '<div class="t-badge">'.Icons::span('building-hospital').'</div>',
            };
        }

        $contact = $this->contacts((array) Arr::get($header, 'lines', []), $values, $department);
        $position = Arr::get($header, 'logo_position', 'left');
        $rule = Arr::get($header, 'show_divider') ? '<div class="rule"></div>' : '';

        if ($position === 'center') {
            return '<div style="text-align:center">'.$logo.$identity.'</div>'
                .($contact !== '' ? '<div style="margin-top:2mm">'.$this->contactsInline((array) Arr::get($header, 'lines', []), $values, $department).'</div>' : '')
                .$rule;
        }

        $logoCell = $logo !== '' ? '<td style="width:1%;padding-right:3.5mm">'.$logo.'</td>' : '';
        $identityCell = '<td'.($position === 'right' ? ' style="text-align:right"' : '').'>'.$identity.'</td>';
        $contactCell = '<td style="width:'.($a4 ? '38%' : '40%').';padding-left:3mm">'.$contact.'</td>';

        $cells = $position === 'right'
            ? $contactCell.$identityCell.str_replace('padding-right:3.5mm', 'padding-left:3.5mm', $logoCell)
            : $logoCell.$identityCell.$contactCell;

        return '<table class="lh"><tr>'.$cells.'</tr></table>'.$rule;
    }

    /**
     * How to reach the branch — one fact per line, behind an icon chosen
     * from what the line contains rather than where it sits.
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $values
     */
    private function contacts(array $lines, array $values, string $department): string
    {
        $rows = '';

        foreach ($this->contactLines($lines, $values, $department) as $line) {
            $rows .= '<tr><td class="ic">'.Icons::span($this->contactIcon($line)).'</td>'
                .'<td>'.$this->link($line).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="contact">'.$rows.'</table>';
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $values
     */
    private function contactsInline(array $lines, array $values, string $department): string
    {
        $parts = array_map(
            fn (string $line) => '<span style="color:#334155">'.Icons::span($this->contactIcon($line), 'color:inherit').' '.$this->e($line).'</span>',
            $this->contactLines($lines, $values, $department),
        );

        return '<div class="contact" style="font-size:inherit">'.implode('&nbsp;&nbsp;&nbsp;', $parts).'</div>';
    }

    /**
     * A line whose only content was an empty placeholder is dropped, as is
     * one that merely repeats the branch name already under the clinic's.
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function contactLines(array $lines, array $values, string $department): array
    {
        $out = [];

        foreach ($lines as $line) {
            $filled = trim($this->fill((string) $line, $values));

            if ($filled !== '' && $filled !== '·' && $filled !== $department) {
                $out[] = $filled;
            }
        }

        return $out;
    }

    /**
     * A contact line as a link where it is one — tapping the number in a PDF
     * opened on a phone dials it, tapping the address writes to it.
     */
    private function link(string $line): string
    {
        $href = match ($this->contactIcon($line)) {
            'mail' => 'mailto:'.$line,
            'phone' => 'tel:'.preg_replace('/[^\d+]/', '', $line),
            'world' => preg_match('~^https?://~i', $line) ? $line : 'https://'.$line,
            default => null,
        };

        return $href === null
            ? $this->e($line)
            : '<a href="'.$this->e($href).'">'.$this->e($line).'</a>';
    }

    private function contactIcon(string $line): string
    {
        return match (true) {
            str_contains($line, '@') => 'mail',
            (bool) preg_match('~(www\.|https?://)~i', $line) => 'world',
            // An address starting "14 Sector 44" is not a telephone.
            (bool) preg_match('/^[+(]?[\d][\d\s()+.-]{5,}$/', $line) => 'phone',
            default => 'map-pin',
        };
    }

    /**
     * What the document is, on the left; its number and date on the right.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function titleRow(string $documentType, array $config, array $values): string
    {
        $header = Arr::get($config, 'header', []);
        $title = $this->titleCase($this->fill(Arr::get($header, 'title', ''), $values));
        $subtitle = $this->fill(Arr::get($header, 'subtitle', ''), $values);
        $subtitle = $subtitle !== '' ? $subtitle : (self::SUBTITLE[$documentType] ?? '');

        // A long name steps down a size rather than breaking beside the number box.
        $words = ($title !== '' ? '<div class="doc-title'.(mb_strlen($title) > 19 ? ' long' : '').'">'.$this->e($title).'</div>' : '')
            .($subtitle !== '' ? '<div class="doc-sub">'.$this->e($subtitle).'</div>' : '');

        $left = '<table><tr>'
            .'<td style="width:'.(Arr::get($config, 'layout.paper', DocumentTypes::PAPER_A4) === DocumentTypes::PAPER_A4 ? '10.5mm' : '9mm').'"><div class="t-badge">'.Icons::span(self::TYPE_ICON[$documentType] ?? 'receipt').'</div></td>'
            .'<td>'.$words.'</td>'
            .'</tr></table>';

        [$label, $number, $others] = $this->numbers($documentType, $values);
        $date = $this->documentDate($values);

        // Number first and largest, the rest as quiet rows under it.
        $rows = '';

        foreach (array_filter([$label => $number, 'Date' => $date, ...$others], fn ($v) => trim((string) $v) !== '') as $key => $value) {
            $lead = $key === $label;
            $rows .= '<tr><td class="k">'.$this->e((string) $key).'</td>'
                .'<td class="v'.($lead ? ' lead' : '').'">'.$this->e((string) $value).'</td></tr>';
        }

        return '<table class="title-row gap"><tr>'
            .'<td>'.$left.'</td>'
            .($rows !== '' ? '<td style="width:1%;padding-left:4mm"><div class="meta"><table>'.$rows.'</table></div></td>' : '')
            .'</tr></table>';
    }

    /**
     * The number the document leads with, and any others it carries.
     *
     * A receipt — or a refund — leads with its own number; the bill behind it
     * is a smaller line under it, and the document number is not printed
     * twice when it is the same number.
     *
     * @param  array<string, string>  $values
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function numbers(string $documentType, array $values): array
    {
        $receipt = (string) ($values['receipt_number'] ?? '');
        $refund = (string) ($values['refund_number'] ?? '');
        $document = (string) ($values['document_number'] ?? '');

        $candidates = array_filter([
            'Refund No' => $refund,
            'Receipt No' => $receipt,
            'Invoice No' => (string) ($values['invoice_number'] ?? ''),
            'Prescription No' => (string) ($values['prescription_number'] ?? ''),
        ], fn ($value) => trim($value) !== '');

        if ($documentType === 'patient_registration' && trim((string) ($values['patient_id'] ?? '')) !== '') {
            $candidates = ['Registration No' => (string) $values['patient_id'], ...$candidates];
        }

        // The document number leads only when there is nothing else; otherwise
        // it is already in the provenance line along the footer.
        if ($candidates === [] && trim($document) !== '') {
            $candidates['Document No'] = $document;
        }

        if ($candidates === []) {
            return ['', '', []];
        }

        $label = (string) array_key_first($candidates);
        $number = (string) array_shift($candidates);

        return [$label, $number, $candidates];
    }

    /** @param array<string, string> $values */
    private function documentDate(array $values): string
    {
        foreach (['refund_date', 'receipt_date', 'invoice_date', 'prescription_date', 'visit_date', 'generated_on'] as $key) {
            if (trim((string) ($values[$key] ?? '')) !== '') {
                return (string) $values[$key];
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function intro(array $config, array $values): string
    {
        $intro = trim($this->fill((string) Arr::get($config, 'body.intro', ''), $values));

        return $intro === '' ? '' : '<div class="intro">'.nl2br($this->e($intro)).'</div>';
    }

    /* ==================================================================
       People
       ================================================================== */

    /**
     * Who the document is about, in one card: the patient, and the doctor
     * beside them across a hairline when there is one.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function people(string $documentType, array $config, array $values): string
    {
        $body = Arr::get($config, 'body', []);

        $cells = array_values(array_filter([
            Arr::get($body, 'show_patient') ? $this->patientCell($values) : '',
            Arr::get($body, 'show_visit') ? $this->doctorCell($documentType, $values) : '',
        ]));

        if ($cells === []) {
            return '';
        }

        $row = '';

        foreach ($cells as $index => $cell) {
            $row .= '<td class="pc'.($index > 0 ? ' split' : '').'"'
                .(count($cells) > 1 && $index === 0 ? ' style="width:56%"' : '').'>'.$cell.'</td>';
        }

        return '<div class="people"><table><tr>'.$row.'</tr></table></div>';
    }

    /** @param array<string, string> $values */
    private function patientCell(array $values): string
    {
        $name = trim((string) ($values['patient_name'] ?? ''));

        if ($name === '') {
            return '';
        }

        $id = trim((string) ($values['patient_id'] ?? ''));
        $age = trim((string) ($values['patient_age'] ?? ''));

        $lines = array_filter([
            $this->joined([
                $age !== '' ? (is_numeric($age) ? $age.' Yrs' : $age) : '',
                (string) ($values['patient_gender'] ?? ''),
                (string) ($values['patient_mobile'] ?? ''),
                (string) ($values['patient_address'] ?? ''),
            ]),
        ]);

        return $this->person('user-filled', false, 'Patient',
            $this->e($name).($id !== '' ? ' <span class="p-id">'.$this->e($id).'</span>' : ''),
            $lines,
        );
    }

    /** @param array<string, string> $values */
    private function doctorCell(string $documentType, array $values): string
    {
        $name = trim((string) ($values['doctor_name'] ?? ''));

        if ($name === '') {
            return '';
        }

        $label = in_array($documentType, ['prescription', 'pharmacy_invoice'], true) ? 'Prescribed By' : 'Consulting Doctor';

        $lines = array_filter([
            $this->joined([
                trim((string) ($values['doctor_registration_number'] ?? '')) !== ''
                    ? 'Reg. No '.$values['doctor_registration_number'] : '',
                trim((string) ($values['visit_id'] ?? '')) !== '' ? 'Visit '.$values['visit_id'] : '',
            ]),
        ]);

        return $this->person('stethoscope', true, $label, $this->e($name), $lines);
    }

    /**
     * An icon, a small capitalised label, a name, and the lines under it.
     *
     * @param  array<int, string>  $lines  escaped already
     */
    private function person(string $icon, bool $light, string $label, string $nameHtml, array $lines): string
    {
        return '<table><tr>'
            .'<td class="av-col"><div class="avatar'.($light ? ' light' : '').'">'.Icons::span($icon).'</div></td>'
            .'<td><div class="ov">'.$this->e($label).'</div>'
            .'<div class="p-name">'.$nameHtml.'</div>'
            .($lines !== [] ? '<div class="p-sub">'.implode('<br>', $lines).'</div>' : '')
            .'</td></tr></table>';
    }

    /**
     * Escaped pieces joined with a pale divider, empties dropped.
     *
     * @param  list<string>  $pieces
     */
    private function joined(array $pieces): string
    {
        $pieces = array_values(array_filter(array_map('trim', $pieces), fn ($piece) => $piece !== ''));

        // Short pieces stay whole, so the line breaks between them; a long
        // one (an address) may wrap inside itself.
        return implode(' <span class="sep">&nbsp;·&nbsp;</span> ', array_map(
            fn (string $piece) => mb_strlen($piece) > 28 ? $this->e($piece) : str_replace(' ', '&nbsp;', $this->e($piece)),
            $pieces,
        ));
    }

    /* ==================================================================
       The registration form
       ================================================================== */

    /**
     * Numbered sections, then the consent the patient signs.
     *
     * Only sections with something in them are printed, and the numbering
     * follows what printed — a form that jumps from 1 to 3 reads as torn.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function registration(array $config, array $values): string
    {
        $sections = [];

        $who = $this->fields([
            ['Full Name', $values['patient_name'] ?? '', ''],
            ['Gender', $values['patient_gender'] ?? '', 'user'],
            ['Date of Birth', $values['patient_dob'] ?? '', 'calendar'],
            ['Age', ($values['patient_age'] ?? '') !== '' && is_numeric($values['patient_age']) ? $values['patient_age'].' Years' : ($values['patient_age'] ?? ''), ''],
        ]);

        $reach = $this->fields([
            ['Mobile Number', $values['patient_mobile'] ?? '', 'phone'],
            ['Email Address', $values['patient_email'] ?? '', 'mail'],
            ['Patient ID', $values['patient_id'] ?? '', 'id'],
        ]);

        if ($who !== '' || $reach !== '') {
            $sections[] = ['user', 'Patient Information',
                '<table><tr>'
                .'<td class="av-col"><div class="avatar">'.Icons::span('user-filled').'</div></td>'
                .'<td>'.$who.'</td></tr></table>'
                .($reach !== '' ? '<div class="subgrid">'.$reach.'</div>' : ''),
            ];
        }

        $address = trim((string) ($values['patient_address'] ?? ''));

        if ($address !== '') {
            $sections[] = ['map-pin', 'Address Information', $this->withAvatar('map-pin', $this->fields([['Address', $address, '']]))];
        }

        $visit = $this->fields([
            ['Registered At', $values['branch_name'] ?? '', ''],
            ['Registration Date', $this->documentDate($values), ''],
            ['Registered By', $values['generated_by'] ?? '', ''],
        ]);

        if ($visit !== '') {
            $sections[] = ['calendar', 'Registration Details', $this->withAvatar('calendar', $visit)];
        }

        $out = '';

        foreach ($sections as $index => [$icon, $title, $content]) {
            $out .= '<div class="sec"><table><tr>'
                .'<td style="width:8mm"><div class="sec-badge">'.Icons::span($icon).'</div></td>'
                .'<td class="sec-title">'.($index + 1).'. '.$this->e($title).'</td>'
                .'</tr></table></div>'
                .'<div class="sec-body">'.$content.'</div>';
        }

        return $out.$this->consent($config, $values);
    }

    private function withAvatar(string $icon, string $content): string
    {
        return '<table><tr>'
            .'<td class="av-col"><div class="avatar pale">'.Icons::span($icon).'</div></td>'
            .'<td>'.$content.'</td></tr></table>';
    }

    /**
     * Label-over-value cells divided by hairlines; empty ones are skipped.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $fields
     */
    private function fields(array $fields): string
    {
        $cells = '';
        $first = true;

        foreach ($fields as [$label, $value, $icon]) {
            if (trim((string) $value) === '') {
                continue;
            }

            $cells .= '<td class="fld'.($first ? ' first' : '').'">'
                .'<div class="l">'.$this->e($label).'</div>'
                .'<div class="v">'.($icon !== '' ? Icons::span($icon).' ' : '').$this->e((string) $value).'</div>'
                .'</td>';
            $first = false;
        }

        return $cells === '' ? '' : '<table><tr>'.$cells.'</tr></table>';
    }

    /**
     * The statement a patient signs. The template's notes, when it has
     * them, are the clinic's own wording.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function consent(array $config, array $values): string
    {
        $text = trim($this->fill((string) Arr::get($config, 'body.notes', ''), $values));

        if ($text === '') {
            $text = 'I hereby confirm that the above information is correct to the best of my knowledge. '
                .'I consent to receive medical treatment and allow the clinic to maintain my medical records for future reference.';
        }

        return '<div class="consent keep"><table><tr>'
            .'<td style="width:11mm"><span class="ti shield">'.$this->glyph('shield-check-filled').'</span></td>'
            .'<td><div class="h">Patient Consent</div><div class="txt">'.nl2br($this->e($text)).'</div>'
            .'<table style="margin-top:2mm"><tr>'
            .'<td style="width:46%"><div class="sign-room"></div><div class="sign-line">Patient Signature</div></td>'
            .'<td style="width:8%"></td>'
            .'<td style="width:46%"><div class="sign-room"></div><div class="sign-line">Date</div></td>'
            .'</tr></table>'
            .'</td></tr></table></div>';
    }

    /* ==================================================================
       Clinical notes and the items table
       ================================================================== */

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function clinical(array $config, array $values): string
    {
        if (! Arr::get($config, 'body.show_clinical')) {
            return '';
        }

        $entries = array_filter([
            'Complaint' => (string) ($values['symptoms'] ?? ''),
            'Diagnosis' => (string) ($values['diagnosis'] ?? ''),
            'Vitals' => (string) ($values['vitals'] ?? ''),
            'Advice' => (string) ($values['advice'] ?? ''),
        ], fn ($value) => trim($value) !== '');

        if ($entries === []) {
            return '';
        }

        $cells = [];

        foreach ($entries as $label => $value) {
            $cells[] = '<div class="fld first clin" style="padding:0 0 1.4mm"><div class="l">'.$this->e($label).'</div>'
                .'<div class="v">'.nl2br($this->e($value)).'</div></div>';
        }

        $half = (int) ceil(count($cells) / 2);
        $grid = count($cells) < 2
            ? implode('', $cells)
            : '<table><tr>'
                .'<td style="width:50%;padding-right:4mm">'.implode('', array_slice($cells, 0, $half)).'</td>'
                .'<td style="width:50%;padding-left:4mm">'.implode('', array_slice($cells, $half)).'</td>'
                .'</tr></table>';

        return $this->card('Clinical Notes', 'report-medical', $grid);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $tables
     */
    private function itemTables(string $documentType, array $config, array $tables): string
    {
        $out = '';
        $bill = in_array($documentType, ['clinic_invoice', 'pharmacy_invoice', 'payment_receipt'], true);

        foreach ((array) Arr::get($config, 'body.tables', []) as $table) {
            $data = $tables[(string) ($table['token'] ?? '')] ?? null;

            // Either shape counts: flat rows, or the grouped form a
            // consolidated bill uses.
            if (! is_array($data) || (($data['rows'] ?? []) === [] && ($data['groups'] ?? []) === [])) {
                continue;
            }

            $title = trim((string) ($table['title'] ?? ''));

            // On a bill the table head says what it is; elsewhere a heading helps.
            if (! $bill && $title !== '') {
                $out .= '<div class="sect-h">'.Icons::span($this->tableIcon($documentType), 'padding-right:1.6mm').$this->e($title).'</div>';
            }

            $out .= $this->grid($data, $documentType);
        }

        return $out;
    }

    private function tableIcon(string $documentType): string
    {
        return match ($documentType) {
            'pharmacy_invoice', 'payment_receipt', 'prescription' => 'pill',
            'document_cover' => 'file-stack',
            default => 'file-invoice',
        };
    }

    /**
     * The items table: a solid head in the accent, ruled rows under it.
     *
     * A grouped table (a consolidated bill) is printed flat with the group
     * as a Type column beside each charge, and the charge carries the
     * group's icon.
     *
     * @param  array{columns?: list<string>, rows?: list<list<string>>, groups?: list<array{title: string, rows: list<list<string>>}>}  $data
     */
    private function grid(array $data, string $documentType): string
    {
        $columns = array_values($data['columns'] ?? []);
        $rows = [];
        $icons = [];
        $grouped = ($data['groups'] ?? []) !== [];

        if ($grouped) {
            array_splice($columns, 2, 0, ['Type']);

            foreach ($data['groups'] as $group) {
                $type = (string) ($group['title'] ?? '');

                foreach ($group['rows'] ?? [] as $row) {
                    $row = array_values($row);
                    array_splice($row, 2, 0, [$type]);
                    $rows[] = $row;
                    $icons[] = self::ROW_ICON[mb_strtolower($type)] ?? 'receipt';
                }
            }
        } else {
            foreach (array_values($data['rows'] ?? []) as $row) {
                $rows[] = array_values($row);
                $icons[] = in_array($documentType, ['pharmacy_invoice', 'payment_receipt', 'prescription'], true) ? 'pill' : '';
            }
        }

        $span = max(count($columns), $rows === [] ? 1 : max(array_map('count', $rows)));
        $numeric = $this->numericColumns($rows, $span);
        $itemColumn = $this->itemColumn($columns);
        $amountColumn = $this->lastNumeric($numeric, $span);

        $align = function (int $index) use ($columns, $numeric): string {
            $heading = mb_strtolower(trim((string) ($columns[$index] ?? '')));

            return match (true) {
                in_array($heading, ['#', 'qty', 'quantity', 'sr', 'sr.', 's.no'], true) => 'mid',
                $numeric[$index] ?? false => 'num',
                default => '',
            };
        };

        $head = '';

        foreach ($columns as $index => $column) {
            $classes = trim(implode(' ', array_filter([
                $index === 0 ? 'first' : '',
                $index === count($columns) - 1 ? 'last' : '',
                $align($index),
            ])));

            $head .= '<th'.($classes !== '' ? ' class="'.$classes.'"' : '').'>'.$this->e((string) $column).'</th>';
        }

        $body = '';

        foreach ($rows as $r => $row) {
            $cells = '';

            for ($index = 0; $index < $span; $index++) {
                $cell = $this->e((string) ($row[$index] ?? ''));
                $classes = trim(implode(' ', array_filter([
                    $index === 0 ? 'first' : '',
                    $align($index),
                    $index === $amountColumn ? 'strong' : '',
                ])));

                if ($index === $itemColumn && ($icons[$r] ?? '') !== '' && $cell !== '') {
                    $cell = '<table><tr><td class="ico" style="width:5mm;border:0;padding:0 1.6mm 0 0">'.Icons::span($icons[$r]).'</td>'
                        .'<td style="border:0;padding:0">'.$cell.'</td></tr></table>';
                }

                $cells .= '<td'.($classes !== '' ? ' class="'.$classes.'"' : '').'>'.$cell.'</td>';
            }

            $body .= '<tr>'.$cells.'</tr>';
        }

        return '<table class="items">'.($head !== '' ? '<thead><tr>'.$head.'</tr></thead>' : '')
            .'<tbody>'.$body.'</tbody></table>';
    }

    /**
     * Which column names the thing charged for: the first headed like an
     * item, else the second column (the first is usually "#").
     *
     * @param  list<string>  $columns
     */
    private function itemColumn(array $columns): int
    {
        foreach ($columns as $index => $column) {
            if (preg_match('/item|service|medicine|name|document/i', (string) $column)) {
                return $index;
            }
        }

        return count($columns) > 1 && trim((string) ($columns[0] ?? '')) === '#' ? 1 : 0;
    }

    /** @param array<int, bool> $numeric */
    private function lastNumeric(array $numeric, int $span): int
    {
        for ($index = $span - 1; $index > 0; $index--) {
            if ($numeric[$index] ?? false) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Which columns hold nothing but numbers. EVERY non-empty value has to
     * be numeric, and the first column is never pushed right — it is a row
     * number or the name of the thing being charged for.
     *
     * @param  list<list<string>>  $rows
     * @return array<int, bool>
     */
    private function numericColumns(array $rows, int $span): array
    {
        $numeric = [];

        for ($column = 0; $column < $span; $column++) {
            $seen = 0;
            $numbers = 0;

            foreach ($rows as $row) {
                $cell = trim((string) ($row[$column] ?? ''));

                if ($cell === '') {
                    continue;
                }

                $seen++;

                if (is_numeric(str_replace(['₹', ',', ' ', '%'], '', $cell))) {
                    $numbers++;
                }
            }

            $numeric[$column] = $column > 0 && $seen > 0 && $numbers === $seen;
        }

        return $numeric;
    }

    /* ==================================================================
       Summary, notes, payment
       ================================================================== */

    /**
     * What the bill comes to beside how it was paid, then any notes across
     * the full width.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function lowerGrid(string $documentType, array $config, array $values): string
    {
        $payment = $this->settlement(
            $documentType,
            $values,
            Arr::get($config, 'layout.paper', DocumentTypes::PAPER_A4) === DocumentTypes::PAPER_A4,
        );

        $summary = $this->summary($documentType, $values, $payment !== '');
        $notes = ($documentType === 'patient_registration' ? '' : $this->remarks($config, $values))
            .$this->terms($documentType, $config, $values);

        return $this->pair($summary, $payment).$notes;
    }

    /** Two cards side by side; alone, a card takes the full width. */
    private function pair(string $left, string $right): string
    {
        if ($left === '' || $right === '') {
            return $left.$right;
        }

        return '<table style="page-break-inside:avoid"><tr>'
            .'<td style="width:52%;padding-right:1.7mm">'.$left.'</td>'
            .'<td style="padding-left:1.7mm">'.$right.'</td>'
            .'</tr></table>';
    }

    /**
     * What the bill comes to. The words go here only when there is no
     * payment card to carry them.
     *
     * @param  array<string, string>  $values
     */
    private function summary(string $documentType, array $values, bool $hasPayment): string
    {
        $total = trim((string) ($values['total'] ?? ''));

        if ($total === '') {
            return '';
        }

        $lines = array_filter([
            'Subtotal' => (string) ($values['subtotal'] ?? ''),
            'Discount' => (string) ($values['discount'] ?? ''),
            'Tax' => (string) ($values['tax'] ?? ''),
        ], fn ($value) => trim($value) !== '');

        // Money owed shows in the payment card when there is one; a refund
        // slip never prints a balance.
        if (! $hasPayment && $documentType !== 'clinic_refund' && $this->owing($values)) {
            $lines['Balance Amount'] = (string) $values['balance'];
        }

        $rows = '';
        $last = count($lines) - 1;
        $i = 0;

        foreach ($lines as $label => $value) {
            $rows .= '<tr'.($i === $last ? ' class="last"' : '').'><td>'.$this->e($label).'</td>'
                .'<td class="v"'.($label === 'Balance Amount' ? ' style="color:#dc2626"' : '').'>'.$this->e($value).'</td></tr>';
            $i++;
        }

        $words = $hasPayment ? '' : $this->words($this->amount($total));

        return '<div class="card white">'
            .$this->cardHead('Payment Summary', 'receipt-2')
            .($rows !== '' ? '<table class="sum">'.$rows.'</table>' : '')
            .'<div class="grand"><table><tr><td>Grand Total</td><td class="v">'.$this->e($total).'</td></tr></table>'
            .($words !== '' ? '<div class="words">'.$this->e($words).'</div>' : '')
            .'</div></div>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function remarks(array $config, array $values): string
    {
        $notes = trim($this->fill((string) Arr::get($config, 'body.notes', ''), $values));

        return $notes === '' ? '' : $this->card('Remarks', 'file-text', '<div class="note">'.nl2br($this->e($notes)).'</div>');
    }

    /**
     * The template's terms, one numbered line each — they are the part of a
     * document somebody may have to point at later.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function terms(string $documentType, array $config, array $values): string
    {
        $terms = trim($this->fill((string) Arr::get($config, 'footer.terms', ''), $values));

        if ($terms === '') {
            return '';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $terms) ?: []), fn ($line) => $line !== ''));
        $rows = '';

        foreach ($lines as $index => $line) {
            $rows .= '<tr>'.(count($lines) > 1 ? '<td class="n">'.($index + 1).'.</td>' : '')
                .'<td>'.$this->e($line).'</td></tr>';
        }

        $title = in_array($documentType, ['pharmacy_invoice', 'payment_receipt', 'prescription'], true)
            ? 'Important Instructions'
            : 'Terms & Conditions';

        return $this->card($title, 'info-circle', '<table class="steps">'.$rows.'</table>');
    }

    /**
     * How the bill was settled, and the square that finds it again.
     *
     * Money going back gets its own card and its own words: printed through
     * the payment rows a refund slip read "Paid Amount ₹300" on a sheet
     * handing ₹300 back.
     *
     * @param  array<string, string>  $values
     */
    private function settlement(string $documentType, array $values, bool $a4 = true): string
    {
        $paid = (string) ($values['amount_paid'] ?? '');
        $refunded = (string) ($values['refund_amount'] ?? '');
        $state = (string) ($values['payment_state'] ?? '');

        if ($refunded !== '') {
            $title = 'Refund Information';
            $rows = $this->rows([
                ['Amount Refunded', $refunded, 'money'],
                ['Refund Mode', $values['refund_method'] ?? '', ''],
                ['Against Receipt', $values['refunded_receipt_number'] ?? '', ''],
                ['Reason', $values['refund_reason'] ?? '', ''],
                ['Refunded On', $values['refund_date'] ?? '', ''],
                ['Refund Status', $state, 'pill'],
            ]);
            $words = $this->words($this->amount($refunded));
        } else {
            $title = 'Payment Information';
            $rows = $this->rows([
                ['Payment Mode', $values['payment_method'] ?? '', ''],
                ['Transaction ID', $values['payment_reference'] ?? '', ''],
                ['Payment Status', $state, 'pill'],
                ['Paid Amount', $paid, 'money'],
                ['Balance Amount', $documentType !== 'clinic_refund' && $this->owing($values) ? (string) $values['balance'] : '', 'owe'],
                ['Paid On', $values['payment_date'] ?? '', ''],
            ]);
            $words = $this->words($this->amount($paid !== '' ? $paid : ($values['total'] ?? '')));
        }

        // Nothing tendered and nothing owed: no card that answers nothing.
        if ($rows === '') {
            return '';
        }

        $body = '<table class="kv">'.$rows.'</table>'
            .($words !== '' ? '<div class="words">'.$this->e($words).'</div>' : '');
        $qr = $this->qrBlock($values, $a4);

        // The square sits inside the card it verifies, not in a card of its own.
        $content = $qr === ''
            ? $body
            : '<table><tr><td>'.$body.'</td>'
                .'<td style="width:'.($a4 ? '22mm' : '17mm').';padding-left:2mm;vertical-align:middle">'.$qr.'</td></tr></table>';

        return $this->card($title, 'credit-card', $content);
    }

    /**
     * Label/value rows; `money` bolds the value, `owe` sets it in red, `pill`
     * prints a payment state as a coloured badge.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $entries
     */
    private function rows(array $entries): string
    {
        $out = '';

        foreach ($entries as [$label, $value, $style]) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $shown = match ($style) {
                'pill' => '<span class="pill '.$this->pillClass($value).'">'.$this->e($value).'</span>',
                default => $this->e($value),
            };

            $class = match ($style) {
                'money' => 'v money',
                'owe' => 'v owe',
                default => 'v',
            };

            $out .= '<tr><td class="k">'.$this->e($label).'</td><td class="'.$class.'">'.$shown.'</td></tr>';
        }

        return $out;
    }

    private function pillClass(string $state): string
    {
        return match ($state) {
            'PAID' => 'paid',
            'PART PAID' => 'part',
            'REFUNDED' => 'refund',
            default => 'unpaid',
        };
    }

    /**
     * The QR square encodes the document's own number, not a URL: this
     * software is deployed per clinic, and no address here is guaranteed to
     * resolve from a patient's phone. A number a counter can search for
     * works on every install.
     *
     * @param  array<string, string>  $values
     */
    private function qrBlock(array $values, bool $a4): string
    {
        $reference = trim((string) ($values['refund_number'] ?? $values['receipt_number'] ?? $values['invoice_number'] ?? $values['document_number'] ?? ''));

        if ($reference === '') {
            return '';
        }

        $qr = $this->qr->dataUri($reference, 220);

        if ($qr === null) {
            return '';
        }

        $size = $a4 ? '17mm' : '13mm';

        return '<div class="qr-box"><img src="'.$qr.'" style="width:'.$size.';height:'.$size.'">'
            .'<div class="qr-t">Scan to verify</div>'
            .'<div class="qr-n">'.$this->e($reference).'</div></div>';
    }

    /* ==================================================================
       Signatures, small print, provenance
       ================================================================== */

    /**
     * The last row: who handed it over and where the copy came from on the
     * left, the signature on the right. A document with no hand-over signs
     * once, under the template's own signature label.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function closing(string $documentType, array $config, array $values): string
    {
        $handover = self::HANDOVER[$documentType] ?? null;
        $signs = $documentType !== 'patient_registration'
            && ($handover !== null || Arr::get($config, 'footer.show_signature'));

        if (! $signs) {
            return '';
        }

        $left = '';

        if ($handover !== null) {
            $by = trim((string) ($values['generated_by'] ?? ''));
            $left = '<div class="s-label">'.$this->e($handover).'</div>'
                .'<div class="s-name">'.$this->e($by !== '' ? $by : '—')
                .' <span class="s-role">· '.$this->e((string) ($values['branch_name'] ?? 'Counter')).'</span></div>';
            $label = 'Authorised Signatory';
            $signer = '';
        } else {
            $label = trim($this->fill((string) Arr::get($config, 'footer.signature_label', ''), $values));
            $label = $label !== '' ? $label : 'Authorised Signatory';
            $doctor = trim((string) ($values['doctor_name'] ?? ''));
            $signer = $doctor !== '' ? '<b style="color:#0f172a">'.$this->e($doctor).'</b><br>' : '';
        }

        return '<table class="signs"><tr>'
            .'<td style="width:58%">'.$left.'</td>'
            .'<td><div class="sign-room"></div><div class="sign-line">'.$signer.$this->e($label).'</div></td>'
            .'</tr></table>';
    }

    /**
     * Footer lines after the first — the first is the band's thank-you.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function smallPrint(array $config, array $values): string
    {
        $lines = array_slice($this->footerLines($config, $values), 1);

        return $lines === []
            ? ''
            : '<div class="smallprint">'.implode('<br>', array_map($this->e(...), $lines)).'</div>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function footerLines(array $config, array $values): array
    {
        $lines = [];

        foreach ((array) Arr::get($config, 'footer.lines', []) as $line) {
            $filled = trim($this->fill((string) $line, $values));

            if ($filled !== '') {
                $lines[] = $filled;
            }
        }

        return $lines;
    }

    /**
     * Where this copy came from, always: two copies of the same bill are
     * otherwise indistinguishable.
     *
     * @param  array<string, string>  $values
     */
    private function provenance(array $values): string
    {
        $stamp = array_filter([
            $values['document_number'] ?? '',
            ($values['generated_on'] ?? '') !== '' ? 'Printed '.$values['generated_on'] : '',
            ($values['generated_by'] ?? '') !== '' ? 'by '.$values['generated_by'] : '',
        ], fn ($part) => trim((string) $part) !== '');

        return $stamp === []
            ? ''
            : '<div class="provenance">'.$this->e(implode(' · ', $stamp)).'</div>';
    }

    /* ==================================================================
       Cards
       ================================================================== */

    private function card(string $title, string $icon, string $content): string
    {
        if (trim($content) === '') {
            return '';
        }

        return '<div class="card">'.$this->cardHead($title, $icon)
            .'<div class="card-b">'.$content.'</div></div>';
    }

    private function cardHead(string $title, string $icon): string
    {
        return '<div class="card-h"><table><tr>'
            .'<td style="width:5.2mm"><div class="tile">'.Icons::span($icon).'</div></td>'
            .'<td class="card-t">'.$this->e($title).'</td>'
            .'</tr></table></div>';
    }

    /* ==================================================================
       The till roll
       ================================================================== */

    /**
     * 80mm of one column: name and contacts centred, then the numbers, the
     * items, the totals and how it was paid. No art — thermal paper prints
     * one colour, and every millimetre is paper.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function receiptPage(string $documentType, array $config, array $values, array $tables): string
    {
        $layout = Arr::get($config, 'layout', []);
        $header = Arr::get($config, 'header', []);
        $margins = Arr::get($layout, 'margin_mm', []);
        $size = (float) (Arr::get($layout, 'font_size', 9) ?: 9);
        $accent = $this->colour(Arr::get($layout, 'accent', '#0b5275'));
        $ink = $this->darken($accent, 0.58);

        $margin = sprintf('%smm %smm %smm %smm',
            (float) ($margins['top'] ?? 4), (float) ($margins['right'] ?? 4),
            (float) ($margins['bottom'] ?? 4), (float) ($margins['left'] ?? 4));

        $department = $this->fill(Arr::get($header, 'department', ''), $values);
        $legal = $this->fill(Arr::get($header, 'legal_name', ''), $values);
        $title = $this->titleCase($this->fill(Arr::get($header, 'title', ''), $values));
        $contacts = $this->contactLines((array) Arr::get($header, 'lines', []), $values, $department);

        $head = '<div class="c"><div class="org">'.$this->e($legal !== '' ? $legal : $title).'</div>'
            .($department !== '' ? '<div>'.$this->e($department).'</div>' : '')
            .($contacts !== [] ? '<div class="m">'.implode('<br>', array_map($this->e(...), $contacts)).'</div>' : '')
            .'</div>'
            .($title !== '' ? '<div class="t">'.$this->e($title).'</div>' : '');

        [$label, $number, $others] = $this->numbers($documentType, $values);

        $meta = $this->plainPairs(array_filter([
            $label => $number,
            ...$others,
            'Date' => $this->documentDate($values),
            'Patient' => (string) ($values['patient_name'] ?? ''),
            'Mobile' => (string) ($values['patient_mobile'] ?? ''),
        ], fn ($value) => trim((string) $value) !== ''), false);

        $items = '';

        foreach ((array) Arr::get($config, 'body.tables', []) as $table) {
            $data = $tables[(string) ($table['token'] ?? '')] ?? null;

            if (! is_array($data)) {
                continue;
            }

            $rows = [];

            foreach ($data['groups'] ?? [] as $group) {
                foreach ($group['rows'] ?? [] as $row) {
                    $rows[] = array_values($row);
                }
            }

            foreach ($data['rows'] ?? [] as $row) {
                $rows[] = array_values($row);
            }

            if ($rows === []) {
                continue;
            }

            $columns = array_values($data['columns'] ?? []);
            $span = max(count($columns), max(array_map('count', $rows)));
            $numeric = $this->numericColumns($rows, $span);

            $items .= '<table class="it"><thead><tr>';
            foreach ($columns as $index => $column) {
                $items .= '<th'.(($numeric[$index] ?? false) ? ' class="n"' : '').'>'.$this->e((string) $column).'</th>';
            }
            $items .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $items .= '<tr>';
                for ($index = 0; $index < $span; $index++) {
                    $items .= '<td'.(($numeric[$index] ?? false) ? ' class="n"' : '').'>'.$this->e((string) ($row[$index] ?? '')).'</td>';
                }
                $items .= '</tr>';
            }
            $items .= '</tbody></table>';
        }

        $totals = [];
        if (trim((string) ($values['total'] ?? '')) !== '') {
            $totals = array_filter([
                'Sub Total' => (string) ($values['subtotal'] ?? ''),
                'Discount' => (string) ($values['discount'] ?? ''),
                'Tax' => (string) ($values['tax'] ?? ''),
                'Total' => (string) $values['total'],
            ], fn ($value) => trim($value) !== '');

            if ($documentType !== 'clinic_refund' && $this->owing($values)) {
                $totals['Balance Amount'] = (string) $values['balance'];
            }
        }

        $paid = array_filter([
            'Paid Amount' => (string) ($values['amount_paid'] ?? ''),
            'Mode' => (string) ($values['payment_method'] ?? ''),
            'Ref.' => (string) ($values['payment_reference'] ?? ''),
            'Status' => (string) ($values['payment_state'] ?? ''),
        ], fn ($value) => trim($value) !== '');

        $lines = $this->footerLines($config, $values);

        $content = $head
            .'<div class="hr"></div>'.$meta
            .($items !== '' ? '<div class="hr"></div>'.$items : '')
            .($totals !== [] ? '<div class="hr"></div>'.$this->plainPairs($totals, true) : '')
            .($paid !== [] ? '<div class="hr"></div>'.$this->plainPairs($paid, false) : '')
            .($lines !== [] ? '<div class="hr"></div><div class="c">'.implode('<br>', array_map($this->e(...), $lines)).'</div>' : '')
            .$this->provenance($values);

        $family = Arr::get($layout, 'font_family') === 'serif' ? "'DejaVu Serif', serif" : "'DejaVu Sans', sans-serif";
        $small = round($size * 0.86, 1);
        $big = round($size * 1.3, 1);

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <style>
            @page { size: 80mm auto; margin: {$margin}; }
            body { font-family: {$family}; font-size: {$size}pt; color: #111827; margin: 0; line-height: 1.3; }
            table { width: 100%; border-collapse: collapse; }
            td, th { vertical-align: top; padding: 0.6mm 0; }
            .c { text-align: center; }
            .org { font-size: {$big}pt; font-weight: bold; color: {$ink}; }
            .m { font-size: {$small}pt; color: #4b5563; }
            .t { margin-top: 2mm; text-align: center; font-weight: bold; color: {$accent}; }
            .hr { border-top: 0.6pt dashed #9ca3af; margin: 2mm 0; }
            .it th { font-size: {$small}pt; text-align: left; color: #ffffff; background-color: {$accent}; padding: 0.8mm 1mm; }
            .it td { font-size: {$small}pt; padding: 0.8mm 1mm; border-bottom: 0.4pt solid #e5e7eb; }
            .n { text-align: right; white-space: nowrap; }
            .k { color: #4b5563; }
            .v { text-align: right; font-weight: bold; }
            .provenance { margin-top: 2mm; text-align: center; font-size: {$small}pt; color: #9ca3af; }
        </style></head><body>
        {$content}
        </body></html>
        HTML;
    }

    /**
     * Label/value rows for the till roll, the value pushed right.
     *
     * @param  array<string, string>  $entries
     */
    private function plainPairs(array $entries, bool $lastBold): string
    {
        $rows = '';
        $last = array_key_last($entries);

        foreach ($entries as $label => $value) {
            $rows .= '<tr><td class="k">'.$this->e((string) $label).'</td>'
                .'<td class="v"'.($lastBold && $label === $last ? ' style="font-size:1.15em"' : '').'>'.$this->e((string) $value).'</td></tr>';
        }

        return $rows === '' ? '' : '<table>'.$rows.'</table>';
    }

    /* ==================================================================
       Helpers
       ================================================================== */

    /** @param array<string, string> $values */
    private function owing(array $values): bool
    {
        return $this->amount($values['balance'] ?? '') > 0;
    }

    /** A formatted money string back to a number. "₹1,240.50" → 1240.5 */
    private function amount(string $money): float
    {
        $digits = preg_replace('/[^0-9.]/', '', $money);

        return $digits === '' || $digits === null ? 0.0 : (float) $digits;
    }

    /**
     * A figure written out, Indian style — lakh and crore, not million.
     */
    private function words(float $amount): string
    {
        if ($amount <= 0) {
            return '';
        }

        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $said = 'Rupees '.$this->spell($rupees);

        if ($paise > 0) {
            $said .= ' and '.$this->spell($paise).' Paise';
        }

        return $said.' Only';
    }

    /** The Indian grouping: crore, lakh, thousand, hundred, and the rest. */
    private function spell(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $unit => $name) {
            if ($number >= $unit) {
                $parts[] = $this->spell(intdiv($number, $unit)).' '.$name;
                $number %= $unit;
            }
        }

        if ($number > 0) {
            $parts[] = $this->belowHundred($number);
        }

        return implode(' ', $parts);
    }

    private function belowHundred(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen'];

        if ($number < 20) {
            return $ones[$number];
        }

        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        return trim($tens[intdiv($number, 10)].' '.$ones[$number % 10]);
    }

    /**
     * Substitute `{{token}}`, tolerating whitespace inside the braces. An
     * unknown placeholder becomes an empty string rather than printing the
     * braces on a document handed to a patient.
     */
    private function fill(?string $text, array $values): string
    {
        // A config read back from jsonb can hold a null where the defaults
        // have an empty string.
        if ($text === null) {
            return '';
        }

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $match) => (string) ($values[mb_strtolower($match[1])] ?? ''),
            $text,
        ) ?? '';
    }

    /** "Clinic payment receipt" → "Clinic Payment Receipt"; capitals already typed are kept. */
    private function titleCase(string $text): string
    {
        return (string) preg_replace_callback('/(^|\s)(\p{Ll})/u', fn (array $m) => $m[1].mb_strtoupper($m[2]), $text);
    }

    /** The raw glyph for an icon, for a span that sets its own classes. */
    private function glyph(string $name): string
    {
        return strip_tags(Icons::span($name));
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
            : '#0b5275';
    }

    /** A shade mixed towards white: 0.90 is a wash, 0.80 a hairline. */
    private function mix(string $hex, float $towardsWhite): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $blend = fn (int $channel) => (int) round($channel + (255 - $channel) * $towardsWhite);

        return sprintf('#%02x%02x%02x', $blend((int) $r), $blend((int) $g), $blend((int) $b));
    }

    /** A shade mixed towards black, for text that must read as the accent's ink. */
    private function darken(string $hex, float $towardsBlack): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $blend = fn (int $channel) => (int) round($channel * (1 - $towardsBlack));

        return sprintf('#%02x%02x%02x', $blend((int) $r), $blend((int) $g), $blend((int) $b));
    }
}
