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
 * TABLES, NOT FLEX. dompdf — the renderer behind this — supports a small, old
 * subset of CSS: no flexbox, no grid, limited positioning. Building the layout
 * from tables from the start is not nostalgia, it is the difference between a
 * letterhead that prints and one that looks right in a browser and collapses
 * in the PDF.
 *
 * WHAT IT IS SUPPOSED TO LOOK LIKE. A bill here is a stack of bordered,
 * rounded cards under a coloured title band, in the accent the branch chose:
 *
 *     letterhead      who the clinic is, and how to reach them
 *     title band      what this document is — the one thing read first
 *     meta + stamp    its numbers, and whether it is settled
 *     cards           patient, the charges, how it was paid
 *     signatures      who took the money, who signs for the clinic
 *     footer band     the clinic's line, and the provenance stamp
 *
 * Three primitives carry that, and each was chosen against dompdf rather than
 * against a browser: solid background colours with a `border-radius` (fine),
 * a background IMAGE for the gradient band because `linear-gradient()` renders
 * as nothing at all (see Gradient), and an icon FONT because the SVG parser is
 * a separate code path with its own gaps (see Icons).
 *
 * Every value is escaped. A patient's name and a doctor's notes are free text
 * that ends up inside markup, and a document that renders somebody's typed
 * `<b>` as bold is the small version of the same bug that renders a script
 * tag.
 */
class DocumentHtml
{
    /**
     * The icon in the title band, per document type.
     *
     * Falls back to a receipt, because everything this renders that is not
     * named here is a bill of some kind.
     */
    private const TYPE_ICON = [
        'patient_registration' => 'user-plus',
        'prescription' => 'prescription',
        'consultation_summary' => 'clipboard-text',
        'clinic_invoice' => 'clipboard-text',
        'pharmacy_invoice' => 'pill',
        'payment_receipt' => 'cash',
        'document_cover' => 'file-stack',
    ];

    /**
     * Who handed the document over — the left-hand signature box.
     *
     * A pharmacy dispenses, a counter receives. Naming it correctly is the
     * difference between a box somebody signs and a box somebody ignores.
     */
    private const HANDOVER = [
        'pharmacy_invoice' => 'Dispensed by',
        'payment_receipt' => 'Received by',
        'clinic_invoice' => 'Received by',
    ];

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
        $receipt = Arr::get($layout, 'paper') === DocumentTypes::PAPER_RECEIPT;

        $sections = [
            $this->header($documentType, $config, $values, $logoData, $receipt),
            $this->body($documentType, $config, $values, $tables, $receipt),
            $this->footer($config, $values),
        ];

        /*
         * THE SHEET IS A CARD, and the footer band is the bottom of it.
         *
         * Printed as loose blocks down a page the layout had no edge: the
         * letterhead started wherever the margin did and the footer floated
         * above whatever paper was left, so a bill with three lines on it read
         * as an unfinished draft. One frame around the lot is what makes it a
         * document rather than a print-out, and it is what the footer's wave
         * curls into at the foot.
         *
         * The provenance line sits OUTSIDE the frame. It is a note about the
         * printing, not part of the bill, and inside the card it read as one
         * more thing the clinic was telling the patient.
         */
        $sheet = '<div class="sheet">'.implode("\n", array_filter($sections)).'</div>';

        return $this->page($layout, $sheet.$this->provenance($values), $receipt);
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
         * It is also why this design is not set in the geometric sans the
         * mockups use: a webfont would have to be bundled, converted and
         * proven to carry ₹ before a single bill could print through it. The
         * hierarchy below — the scale, the weights, the colour — is doing the
         * work a typeface would otherwise do.
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
         * FOUR SHADES OF THE ACCENT, mixed in PHP rather than left to CSS.
         *
         * dompdf's support for colour functions is not something to rely on,
         * and a card head whose background silently failed to render would
         * print a heading on nothing. Mixing with white here means the value
         * that reaches the renderer is a plain hex it cannot get wrong.
         *
         *   lite  the far end of the title band's ramp, and the tile on it
         *   edge  a border that belongs to the accent without competing
         *   tint  a wash a heading can sit on
         *   pale  a wash a whole total row can sit on
         */
        $lite = $this->mix($accent, 0.42);
        $edge = $this->mix($accent, 0.74);
        $tint = $this->mix($accent, 0.90);
        $pale = $this->mix($accent, 0.95);

        /* The ramp across the title band, and the swell under the footer.
           A solid background-color is set under each, so a machine with no GD
           prints a flat band rather than a blank one. */
        $stretch = ' background-repeat: repeat-y; background-size: 100% 100%;';

        $ramp = $this->gradient->dataUri($accent, $lite);
        $band = 'background-color: '.$accent.';'
            .($ramp !== null ? ' background-image: url('.$ramp.');'.$stretch : '');

        $swell = $this->gradient->wave($pale, $tint);
        $foot = 'background-color: '.$pale.';'
            .($swell !== null ? ' background-image: url('.$swell.');'.$stretch : '');

        /*
         * SPACING AND THE LARGEST TYPE FOLLOW THE SHEET.
         *
         * The padding that makes an A4 bill look considered cost a
         * prescription its second half — at A4's measure a prescription on A5
         * ran straight onto a second slip. A smaller page is not a smaller
         * version of a bigger one; it gets the same type scale, tightened.
         */
        $roomy = Arr::get($layout, 'paper', DocumentTypes::PAPER_A4) === DocumentTypes::PAPER_A4;

        /*
         * A TYPE SCALE, not a pile of near-identical sizes.
         *
         * Six steps, each clearly apart from its neighbours, because nobody
         * can see the difference between 0.85 and 0.88 of a base size — and a
         * page where nothing is deliberately larger than anything else is what
         * makes a document read as "generated" rather than designed.
         */
        $nano = $this->scale($size, 0.64);    // captions, the signatory line
        $micro = $this->scale($size, 0.74);   // labels, table heads, contacts
        $small = $this->scale($size, 0.86);   // values, table body
        $lead = $this->scale($size, 1.22);    // the band title, the grand total
        /*
         * The clinic's name, and the ONE step that is paper-aware: at 1.68 of
         * an 11pt base it wanted more than the half of an A5 sheet that the
         * letterhead leaves it, so "CarePlus Healthcare" broke across two
         * lines — on the line whose whole job is to say the name once.
         */
        $display = $this->scale($size, $roomy ? 1.68 : 1.45);
        $mark = $this->scale($size, 3.60);    // the watermark glyph on the band

        /* Uppercase needs air between the letters or it sets as a block. */
        $track = $this->scale($size, 0.055).'pt';

        $iconFamily = Icons::FAMILY;

        /*
         * TIGHT, BECAUSE A SECOND SHEET IS THE ONE THING A COUNTER NOTICES.
         *
         * Every one of these was a third larger, and a real consolidated bill
         * — a registration line, a consultation, a test and two pharmacy rows
         * — ran its payment panel, its signatures and its footer onto a page
         * two that was four-fifths white. A clinic printing a hundred bills a
         * day pays for that in paper, in toner and in a stapler, and it is the
         * first thing they ask to have taken away.
         *
         * Nothing here is smaller: the type scale is untouched and every card
         * still has air inside it. What went is the slack BETWEEN things, which
         * is the space a reader was not using anyway.
         */
        $leading = $roomy ? '1.32' : '1.22';
        $gap = $roomy ? '5px' : '4px';
        $padBand = $roomy ? '7px 10px' : '6px 8px';
        $padHead = $roomy ? '4px 8px' : '3px 6px';
        $padBody = $roomy ? '6px 9px' : '5px 7px';
        $padTh = $roomy ? '4px 6px' : '3px 5px';
        $padTd = $roomy ? '4px 6px' : '3px 5px';
        /* The inset between the card's own border and what is printed on it.
           The footer band reaches back out through it to the card's edge. */
        $inset = $roomy ? '4mm' : '3mm';
        /* The whole signature box, and the blank part of it above the rule.
           13mm still takes a signature; 17mm took a signature and a hand. */
        $signBox = $roomy ? '11mm' : '8mm';
        $signRoom = $roomy ? '7mm' : '4mm';

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <style>
            {$page}
            body { font-family: {$family}; font-size: {$size}pt; color: #1f2937; margin: 0;
                   line-height: {$leading}; }
            table { width: 100%; border-collapse: collapse; }
            td, th { vertical-align: top; }
            .muted { color: #6b7280; font-size: {$small}pt; }
            .accent { color: {$accent}; }

            /* ---- the sheet ---------------------------------------------- */
            /*
             * The frame that makes the page a document. No padding at the
             * foot: the footer band fills it, and its own wave runs into the
             * card's bottom corners.
             */
            .sheet { border: 0.6pt solid #dfe5ee; border-radius: 10px;
                     padding: {$inset} {$inset} 0; }

            /*
             * The icon font. PdfRenderer strips every one of these spans when
             * the font could not be registered — see Icons.
             */
            .ti { font-family: {$iconFamily}; font-style: normal; font-weight: normal;
                  line-height: 1; }

            /* ---- letterhead -------------------------------------------- */
            /*
             * The clinic's name in ink, the branch in the accent beneath it.
             *
             * Both used to be the accent colour, which put two competing
             * headings in the same corner and left neither looking like the
             * more important one. One focal point: the name of the practice is
             * the biggest thing on the page, and the accent is spent on what
             * sits under it.
             */
            .org { font-size: {$display}pt; font-weight: bold; color: #0f172a;
                   line-height: 1.15; }
            .dept { font-size: {$small}pt; font-weight: bold; color: {$accent};
                    line-height: 1.35; }
            .tagline { font-size: {$nano}pt; color: #94a3b8; line-height: 1.5; }
            .reg { font-size: {$nano}pt; color: #6b7280; line-height: 1.5; }

            /* A tinted square behind an uploaded logo, so a small or pale mark
               still reads as a mark rather than as a stray picture. */
            .mark { background-color: {$tint}; border-radius: 8px; text-align: center;
                    padding: 2mm; }
            /*
             * AND A MARK WHEN THERE IS NO LOGO. Most branches never upload
             * one, which left the letterhead as a line of text against a lot
             * of white — the corner a reader looks at first, saying nothing.
             * The same ramp as the title band, so the two read as one identity
             * rather than as a badge that wandered in.
             */
            .mark-tile { {$band} border-radius: 9px; width: 13mm; padding: 2.4mm 0;
                         text-align: center; color: #ffffff; font-size: {$display}pt; }

            /* How to reach them — one fact per line, each with its own icon,
               because a run-on "phone · email" line is the part of a
               letterhead nobody can find anything in. */
            .contact td { padding: 1px 0; font-size: {$micro}pt; color: #475569; }
            .contact .ic { width: 4mm; color: {$accent}; }

            .rule { border-bottom: 0.6pt solid #e2e8f0; height: 0; margin: {$gap} 0 0; }

            /* ---- the title band ---------------------------------------- */
            /*
             * WHAT THIS DOCUMENT IS, said once and loudly.
             *
             * Somebody holding a bill looks for "Invoice" before they read the
             * clinic's address. It used to print as one more grey line under
             * the phone number, which is why the old layout read as a letter
             * rather than as a document.
             */
            .band { {$band} border-radius: 7px; padding: {$padBand}; margin-top: {$gap}; }
            .band td { vertical-align: middle; }
            /* WHITE, with the glyph in the accent — not a paler accent with a
               white glyph. On the ramp a tinted tile reads as a smudge; a
               white one reads as a badge pinned to the band. */
            .band-tile { background-color: #ffffff; border-radius: 6px; width: 7mm;
                         padding: 1.3mm 0; text-align: center; color: {$accent};
                         font-size: {$lead}pt; }
            .band-title { font-size: {$lead}pt; font-weight: bold; color: #ffffff;
                          text-transform: uppercase; letter-spacing: {$track};
                          line-height: 1.2; }
            .band-sub { font-size: {$micro}pt; color: {$tint}; line-height: 1.5; }
            /* The oversized glyph at the right edge. `line-height: 0.7` keeps
               it from setting the height of the band it decorates. */
            .band-mark { color: {$lite}; font-size: {$mark}pt; line-height: 0.7;
                         text-align: right; }

            /* ---- the numbers, and whether it is settled ----------------- */
            .meta { margin-top: {$gap}; }
            .kv td { padding: 0.5px 0; font-size: {$small}pt; }
            /*
             * `width: 1%` with `nowrap` is the table-layout idiom for "as
             * narrow as the widest label and no narrower". A percentage width
             * put the colons a third of the way across the card with a hand's
             * width of nothing between each label and its own answer.
             */
            .kv .k { color: #64748b; white-space: nowrap; width: 1%;
                     padding-right: 2mm; }
            .kv .c { color: #cbd5e1; width: 1%; padding-right: 2mm; }
            .kv .v { font-weight: bold; color: #0f172a; }

            .stamp-box { border-radius: 7px; text-align: center; padding: 4px 8px; }
            .stamp-box .s-i { font-size: {$lead}pt; width: 6mm; }
            .stamp-box .s-t { font-weight: bold; font-size: {$lead}pt;
                              letter-spacing: {$track}; text-align: left; }
            .stamp-box .s-n { font-size: {$nano}pt; }
            .is-paid { background-color: #e9f8ef; border: 0.6pt solid #a7dbbd; color: #14804a; }
            .is-part { background-color: #fdf4e3; border: 0.6pt solid #e9c98a; color: #92650a; }
            .is-unpaid { background-color: #fdeded; border: 0.6pt solid #f0b4b4; color: #a21d1d; }

            /* ---- the cards everything else sits in ---------------------- */
            .card { border: 0.6pt solid #e2e8f0; border-radius: 7px; margin-top: {$gap}; }
            /*
             * A card of KNOWN, SHORT length is never split across a page
             * boundary. The payment panel printed its heading at the foot of
             * page one and its figures at the head of page two, which is the
             * one card on a bill that has to be read as a unit.
             *
             * Asked for on the wrong card it costs a whole page: a card of
             * free text — clinical notes, an items table — can legitimately be
             * longer than the space left, and refusing to break it pushes the
             * lot onto the next sheet and leaves half of this one blank. Only
             * cards whose height the layout fixes ask for it.
             */
            .card.keep { page-break-inside: avoid; }
            .card-h { background-color: {$tint}; border-bottom: 0.6pt solid {$edge};
                      border-radius: 6px 6px 0 0; padding: {$padHead}; }
            .card-h td { vertical-align: middle; }
            .card-h .i { color: {$accent}; width: 5mm; font-size: {$small}pt; }
            .card-h .t { font-weight: bold; color: #0f172a; font-size: {$small}pt; }
            .card-b { padding: {$padBody}; }
            /* The items table is the card, so it runs to the border. */
            .card-b.flush { padding: 0; }
            .col-split { border-left: 0.6pt solid #e2e8f0; }

            /* ---- the items table --------------------------------------- */
            /*
             * A FULL GRID, unlike the tables in the app's own screens. On a
             * bill somebody checks a quantity against a rate against an
             * amount, reading across a row and down a column in the same
             * glance, and the rules are what keep that honest on paper — where
             * there is no hover to hold the row for you.
             */
            .grid th { background-color: {$accent}; color: #ffffff; padding: {$padTh};
                       text-align: left; font-size: {$micro}pt; font-weight: bold;
                       border: 0.6pt solid {$lite}; }
            .grid td { border: 0.6pt solid #e2e8f0; padding: {$padTd};
                       font-size: {$small}pt; }
            /* Zebra, so a wide row is readable across without a ruler. */
            .grid tr.alt td { background-color: #fbfcfe; }
            .grid .num { text-align: right; }
            /* A category heading inside the table — a band, not a row of data,
               so it carries the accent wash and no cell rules. */
            .grid tr.grp td { background-color: {$tint}; color: {$accent}; font-weight: bold;
                              font-size: {$micro}pt; text-transform: uppercase;
                              letter-spacing: {$track}; border-color: {$edge}; }

            /* The totals hang off the right of the same table, so every figure
               lines up under the column it was added from. */
            .grid .void { border: 0; }
            /* The figure never wraps; the label may. On 80mm of till roll a
               "Total Amount" that will not break is a "Total Amount" that
               takes the amount column off the edge of the paper with it. */
            .grid .tk { text-align: right; color: #475569; }
            .grid .tv { text-align: right; font-weight: bold; color: #0f172a;
                        white-space: nowrap; }
            .grid tr.grand td { background-color: {$pale}; color: {$accent};
                                border-color: {$edge}; font-size: {$lead}pt;
                                font-weight: bold; }
            /* What is still owed, said once and loudly — a bill whose balance
               reads the same weight as its tax line is a bill people misread. */
            .grid tr.due td { background-color: #fdeded; color: #a21d1d;
                              border-color: #f0b4b4; font-weight: bold; }

            /* ---- how it was settled ------------------------------------ */
            .qr-cap { font-size: {$nano}pt; color: #64748b; text-align: center;
                      line-height: 1.3; }

            /* Room to actually sign in. The rule used to sit a few points
               under the last table row, which left a signature line nobody
               could write on without crossing the row above it. */
            .sign-box { height: {$signBox}; }
            .sign-room { height: {$signRoom}; }
            .sign-line { border-top: 0.6pt solid #cbd5e1; padding-top: 2px;
                         text-align: center; font-size: {$nano}pt; color: #94a3b8; }
            .sign-name { font-weight: bold; color: #0f172a; font-size: {$small}pt; }
            .sign-role { color: #6b7280; font-size: {$nano}pt; }

            /* ---- the small print --------------------------------------- */
            .terms { border: 0.6pt solid #e2e8f0; border-radius: 7px; background-color: #fafbfc;
                     padding: {$padBody}; margin-top: {$gap}; color: #4b5563;
                     line-height: 1.5; font-size: {$nano}pt; }

            /*
             * Reaches back out through the sheet's inset to sit on its bottom
             * corners, which is the difference between a footer and a box that
             * happens to be last. Only the bottom corners are rounded — the
             * top edge is a rule across the card.
             */
            .fband { {$foot} border-top: 0.6pt solid {$edge};
                     border-radius: 0 0 9px 9px; padding: {$padBody};
                     margin: {$gap} -{$inset} 0; text-align: center; }
            .fband .h { font-weight: bold; color: {$accent}; font-size: {$small}pt; }
            .fband .s { color: #64748b; font-size: {$nano}pt; line-height: 1.5; }

            /* The provenance line, always. A printed medical document that
               cannot say when it was produced is one nobody can place
               afterwards. */
            .provenance { margin-top: 5px; color: #9ca3af; font-size: {$nano}pt;
                          text-align: center; }

            .block { margin-top: {$gap}; }
        </style></head><body>
        {$content}
        </body></html>
        HTML;
    }

    /**
     * A lighter shade of the accent, mixed towards white.
     *
     * `$towardsWhite` of 0.90 is a wash you can print a heading on; 0.74 is a
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
     * The letterhead, and the title band under it.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function header(string $documentType, array $config, array $values, ?string $logoData, bool $receipt): string
    {
        $header = Arr::get($config, 'header', []);

        $legal = $this->fill(Arr::get($header, 'legal_name', ''), $values);
        $department = $this->fill(Arr::get($header, 'department', ''), $values);
        $tagline = $this->fill(Arr::get($header, 'tagline', ''), $values);
        $registration = $this->fill(Arr::get($header, 'registration_no', ''), $values);
        $title = $this->fill(Arr::get($header, 'title', ''), $values);
        $subtitle = $this->fill(Arr::get($header, 'subtitle', ''), $values);

        /*
         * Who this clinic is: the practice, the branch, and its own line.
         */
        $identity = '<div class="org">'.$this->e($legal !== '' ? $legal : $title).'</div>'
            .($department !== '' ? '<div class="dept">'.$this->e($department).'</div>' : '')
            .($tagline !== '' ? '<div class="tagline">'.$this->e($tagline).'</div>' : '')
            .($registration !== '' ? '<div class="reg">'.$this->e($registration).'</div>' : '');

        /*
         * The clinic's mark: what was uploaded, or a drawn one standing in.
         *
         * Falling back to NOTHING is what made the letterhead look unfinished
         * — most branches never upload a logo, and the corner a reader looks
         * at first was a line of text and a lot of white.
         */
        $logo = match (true) {
            ! Arr::get($header, 'show_logo') => '',
            $logoData !== null => '<div class="mark"><img src="'.$logoData
                .'" style="max-height:14mm;max-width:30mm;"></div>',
            default => '<div class="mark-tile">'.Icons::span('building-hospital').'</div>',
        };

        /* A drawn mark is 13mm and an uploaded one may be 30mm, and a column
           sized for the larger leaves the drawn one floating a centimetre from
           the name it belongs to. */
        $logoColumn = $logoData !== null ? '34mm' : '17mm';

        $contact = $this->contacts((array) Arr::get($header, 'lines', []), $values, $department);

        $position = Arr::get($header, 'logo_position', 'left');
        $rule = Arr::get($header, 'show_divider') ? '<div class="rule"></div>' : '';

        /*
         * A till roll is 80mm of one column. Two columns of anything on it
         * would be four words per line, so everything stacks and centres.
         */
        if ($receipt || $position === 'center') {
            $letterhead = '<div style="text-align:center">'.$logo.$identity.'</div>'
                .'<div style="margin-top:4px">'.$contact.'</div>';
        } else {
            [$first, $second] = $position === 'right'
                ? ['<td style="vertical-align:top">'.$identity.'</td>'
                    .($logo !== '' ? '<td style="width:'.$logoColumn.';vertical-align:top">'.$logo.'</td>' : ''), '']
                : [($logo !== '' ? '<td style="width:'.$logoColumn.';vertical-align:top">'.$logo.'</td>' : '')
                    .'<td style="vertical-align:top'.($logo !== '' ? ';padding-left:4mm' : '').'">'.$identity.'</td>', ''];

            /*
             * A SHARE OF THE MEASURE, not a fixed 56mm. On A4 that width was
             * right; on A5 it left the clinic's own name so little room that
             * "CarePlus Healthcare" broke across two lines under a logo — a
             * letterhead whose first job is to say the name once.
             */
            $letterhead = '<table><tr>'.$first.$second
                .'<td style="width:40%;vertical-align:top;padding-left:5mm">'.$contact.'</td>'
                .'</tr></table>';
        }

        return $letterhead.$rule.$this->band($documentType, $title, $subtitle, $receipt);
    }

    /**
     * How to reach the branch — one fact per line, each behind its own icon.
     *
     * The icon is chosen from what the line CONTAINS rather than from its
     * position, so a branch that reorders its address lines — or drops the one
     * it does not have — still gets a pin against the address and an envelope
     * against the email.
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $values
     */
    private function contacts(array $lines, array $values, string $department): string
    {
        $rows = '';

        foreach ($lines as $line) {
            $filled = trim($this->fill((string) $line, $values));

            /*
             * A line whose only content was an empty placeholder is dropped
             * rather than printed as a blank row — a letterhead with a gap
             * where the phone number should be reads as a broken document.
             * So is one that merely repeats the branch name already set under
             * the clinic's own, which is what a template written before the
             * letterhead had a branch line would otherwise do.
             */
            if ($filled === '' || $filled === '·' || $filled === $department) {
                continue;
            }

            $rows .= '<tr><td class="ic">'.Icons::span($this->contactIcon($filled)).'</td>'
                .'<td>'.$this->e($filled).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="contact">'.$rows.'</table>';
    }

    private function contactIcon(string $line): string
    {
        return match (true) {
            str_contains($line, '@') => 'mail',
            (bool) preg_match('~(www\.|https?://)~i', $line) => 'world',
            /* Digits, spaces and the punctuation a phone number is written
               with, and nothing else — an address starting "14 Sector 44"
               is not a telephone. */
            (bool) preg_match('/^[+(]?[\d][\d\s()+.-]{5,}$/', $line) => 'phone',
            default => 'map-pin',
        };
    }

    /**
     * The coloured band that says what the document is.
     */
    private function band(string $documentType, string $title, string $subtitle, bool $receipt): string
    {
        if ($title === '') {
            return '';
        }

        $icon = self::TYPE_ICON[$documentType] ?? 'receipt';

        $words = '<div class="band-title">'.$this->e($title).'</div>'
            .($subtitle !== '' ? '<div class="band-sub">'.$this->e($subtitle).'</div>' : '');

        /* The watermark is decoration, and 80mm has no width to spare. */
        $watermark = $receipt
            ? ''
            : '<td class="band-mark" style="width:16mm">'.Icons::span($icon).'</td>';

        return '<div class="band"><table><tr>'
            .'<td style="width:7mm"><div class="band-tile">'.Icons::span($icon).'</div></td>'
            .'<td style="padding-left:3mm">'.$words.'</td>'
            .$watermark
            .'</tr></table></div>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function body(string $documentType, array $config, array $values, array $tables, bool $receipt): string
    {
        $body = Arr::get($config, 'body', []);
        $out = [];

        $out[] = $this->meta($values, $receipt);

        $intro = trim($this->fill((string) Arr::get($body, 'intro', ''), $values));

        if ($intro !== '') {
            $out[] = '<div class="block">'.nl2br($this->e($intro)).'</div>';
        }

        /*
         * WHO, and WHEN — side by side in one card.
         *
         * They used to be two stacked tables, which ran seven near-empty rows
         * down a page that then had no room for what the document was actually
         * about. They belong together: both answer "whose document is this",
         * and a reader checks them in one glance before reading anything else.
         */
        $who = [];

        if (Arr::get($body, 'show_patient')) {
            $who = [
                'Name' => $values['patient_name'] ?? '',
                'Patient ID' => $values['patient_id'] ?? '',
                'Age / Gender' => trim(($values['patient_age'] ?? '').' / '.($values['patient_gender'] ?? ''), ' /'),
                'Mobile No' => $values['patient_mobile'] ?? '',
            ];
        }

        if (Arr::get($body, 'show_visit')) {
            $who['Doctor'] = $values['doctor_name'] ?? '';
            $who['Reg. No'] = $values['doctor_registration_number'] ?? '';
            $who['Visit ID'] = $values['visit_id'] ?? '';
        }

        /*
         * ONE LIST, SPLIT IN HALF — not "patient on the left, visit on the
         * right". A pharmacy bill knows nothing about a doctor, so the visit
         * side came out holding a single line with the whole right half of the
         * card empty beside it. Halving whatever there actually is fills the
         * card whichever document type is printing.
         */
        if ($who !== []) {
            $out[] = $this->card('Patient details', 'user', $this->columns($who, $receipt), false, true);
        }

        if (Arr::get($body, 'show_clinical')) {
            $clinical = '';

            foreach ([
                'Complaint' => $values['symptoms'] ?? '',
                'Diagnosis' => $values['diagnosis'] ?? '',
                'Vitals' => $values['vitals'] ?? '',
                'Advice' => $values['advice'] ?? '',
            ] as $label => $value) {
                if (trim((string) $value) !== '') {
                    $clinical .= '<div class="kv" style="margin-bottom:3px">'
                        .'<div class="muted" style="color:#64748b">'.$this->e($label).'</div>'
                        .'<div style="font-weight:bold;color:#0f172a">'.nl2br($this->e((string) $value)).'</div>'
                        .'</div>';
                }
            }

            if ($clinical !== '') {
                $out[] = $this->card('Clinical notes', 'report-medical', $clinical, false);
            }
        }

        /*
         * THE TOTALS BELONG TO THE ITEMS TABLE, as trailing rows of it.
         *
         * Printed as a separate right-hand block they sat a column or two out
         * from the amounts they add up — which on a bill somebody is checking
         * is the difference between "the sum of the column above" and "a
         * number somebody typed". Only when there is no table to hang them off
         * do they print on their own.
         */
        $totals = $this->totalRows($values);
        $printed = false;

        foreach ((array) Arr::get($body, 'tables', []) as $table) {
            $token = (string) ($table['token'] ?? '');
            $data = $tables[$token] ?? null;

            /*
             * Either shape counts as "has something to print": a flat list of
             * rows, or the grouped form a consolidated bill uses. Testing only
             * `rows` skipped every grouped table, which on an invoice meant
             * printing a total with no lines above it.
             */
            $hasRows = is_array($data)
                && (($data['rows'] ?? []) !== [] || ($data['groups'] ?? []) !== []);

            if (! $hasRows) {
                continue;
            }

            $trailing = $printed ? [] : $totals;
            $printed = $printed || $trailing !== [];

            $out[] = $this->card(
                (string) ($table['title'] ?? ''),
                $this->tableIcon($documentType),
                $this->grid($data, $trailing),
                true,
            );
        }

        // An invoice's totals are the point of it, so they are printed whether
        // or not the items table rendered.
        if (! $printed && $totals !== []) {
            $out[] = $this->card('Summary', 'file-invoice', $this->grid(
                ['columns' => [], 'rows' => []],
                $totals,
            ), true);
        }

        $out[] = $this->settlement($documentType, $values, $receipt);

        $notes = trim($this->fill((string) Arr::get($body, 'notes', ''), $values));

        if ($notes !== '') {
            $out[] = '<div class="block muted">'.nl2br($this->e($notes)).'</div>';
        }

        return implode("\n", array_filter($out));
    }

    /** The icon on the items card — a pill for a pharmacy, a form elsewhere. */
    private function tableIcon(string $documentType): string
    {
        return match ($documentType) {
            'pharmacy_invoice', 'payment_receipt' => 'pill',
            'prescription' => 'prescription',
            'document_cover' => 'file-stack',
            default => 'file-invoice',
        };
    }

    /**
     * The document's own numbers, and the stamp saying whether it is settled.
     *
     * @param  array<string, string>  $values
     */
    private function meta(array $values, bool $receipt): string
    {
        $facts = $this->pairs([
            'Invoice No' => $values['invoice_number'] ?? '',
            'Prescription No' => $values['prescription_number'] ?? '',
            'Document No' => $values['document_number'] ?? '',
            'Date' => $values['invoice_date'] ?? $values['prescription_date'] ?? $values['visit_date'] ?? '',
            'Printed' => $values['generated_on'] ?? '',
        ]);

        $stamp = $this->stamp((string) ($values['payment_state'] ?? ''));

        if ($facts === '' && $stamp === '') {
            return '';
        }

        // A till roll has one column; two would be four words wide.
        if ($receipt) {
            return '<div class="meta">'.$facts.($stamp !== '' ? '<div style="margin-top:5px">'.$stamp.'</div>' : '').'</div>';
        }

        if ($stamp === '') {
            return '<div class="meta">'.$facts.'</div>';
        }

        return '<table class="meta"><tr>'
            .'<td style="vertical-align:top;padding-right:6mm">'.$facts.'</td>'
            .'<td style="width:48mm;vertical-align:top">'.$stamp.'</td>'
            .'</tr></table>';
    }

    /**
     * The stamp somebody looks for before anything else on a bill they have
     * already paid. Green for settled, amber for part paid, red for owing —
     * said once, large, and nowhere else.
     */
    private function stamp(string $state): string
    {
        if ($state === '') {
            return '';
        }

        [$class, $icon, $note] = match ($state) {
            'PAID' => ['is-paid', 'circle-check', 'Thank you for your payment.'],
            'PART PAID' => ['is-part', 'clock', 'A balance is still outstanding.'],
            default => ['is-unpaid', 'circle-x', 'Payment has not been received.'],
        };

        return '<div class="stamp-box '.$class.'">'
            .'<table><tr>'
            .'<td class="s-i">'.Icons::span($icon).'</td>'
            .'<td class="s-t">'.$this->e($state).'</td>'
            .'</tr></table>'
            .'<div class="s-n">'.$this->e($note).'</div>'
            .'</div>';
    }

    /**
     * A label/value block, with the colon in a column of its own.
     *
     * The label set small and grey, the value in ink beside it, and the colons
     * lined up down the page — which is the whole job of a panel somebody
     * scans rather than reads.
     *
     * @param  array<string, string>  $entries
     */
    private function pairs(array $entries): string
    {
        $rows = '';

        foreach ($entries as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            $rows .= '<tr><td class="k">'.$this->e((string) $label).'</td>'
                .'<td class="c">:</td>'
                .'<td class="v">'.$this->e((string) $value).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="kv">'.$rows.'</table>';
    }

    /**
     * A run of pairs, split down the middle of a card.
     *
     * TWO COLUMNS, not one. Stretching a single label/value table across the
     * page put the labels hard left and their values a third of the way in,
     * with the whole right half of the card empty — the values read as
     * stranded rather than as a pair, and it halves the distance a reader's
     * eye travels from a label to its value.
     *
     * @param  array<string, string>  $entries
     */
    private function columns(array $entries, bool $receipt): string
    {
        $filled = array_filter($entries, fn ($value) => trim((string) $value) !== '');

        if ($filled === []) {
            return '';
        }

        // A till roll has one column; two would be four words wide. So is a
        // card holding two or three facts — splitting those leaves a column
        // with one line in it.
        if ($receipt || count($filled) < 4) {
            return $this->pairs($filled);
        }

        $half = (int) ceil(count($filled) / 2);

        return '<table><tr>'
            .'<td style="width:50%;padding-right:5mm">'
            .$this->pairs(array_slice($filled, 0, $half, true)).'</td>'
            .'<td class="col-split" style="width:50%;padding-left:5mm">'
            .$this->pairs(array_slice($filled, $half, null, true)).'</td>'
            .'</tr></table>';
    }

    /**
     * One bordered card: a tinted head with an icon, and a body.
     *
     * `$flush` for a card whose body IS a table — the grid runs to the card's
     * own border rather than sitting inside padding, which is what keeps the
     * heading band and the table's own head reading as one object.
     */
    private function card(string $title, string $icon, string $content, bool $flush, bool $keep = false): string
    {
        if (trim($content) === '') {
            return '';
        }

        $head = $title === '' ? '' : '<div class="card-h"><table><tr>'
            .'<td class="i">'.Icons::span($icon).'</td>'
            .'<td class="t">'.$this->e($title).'</td>'
            .'</tr></table></div>';

        return '<div class="card'.($keep ? ' keep' : '').'">'.$head
            .'<div class="card-b'.($flush ? ' flush' : '').'">'.$content.'</div></div>';
    }

    /**
     * What the bill comes to — the rows that hang off the foot of the table.
     *
     * @param  array<string, string>  $values
     * @return list<array{label: string, value: string, class: string}>
     */
    private function totalRows(array $values): array
    {
        if (trim((string) ($values['total'] ?? '')) === '') {
            return [];
        }

        $rows = [];

        foreach ([
            ['Sub Total', $values['subtotal'] ?? '', ''],
            ['Discount', $values['discount'] ?? '', ''],
            ['Tax', $values['tax'] ?? '', ''],
            ['Total Amount', $values['total'] ?? '', 'grand'],
        ] as [$label, $value, $class]) {
            if (trim((string) $value) === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => (string) $value, 'class' => $class];
        }

        /*
         * The balance is shown only when something is actually owed. A settled
         * bill printing "Balance ₹0.00" in a highlighted band invites the one
         * question the document exists to answer.
         */
        if ($this->owing($values)) {
            $rows[] = ['label' => 'Balance due', 'value' => (string) $values['balance'], 'class' => 'due'];
        }

        return $rows;
    }

    /** @param array<string, string> $values */
    private function owing(array $values): bool
    {
        return $this->amount($values['balance'] ?? '') > 0;
    }

    /**
     * The items table, with the totals as its last rows.
     *
     * @param  array{columns?: list<string>, rows?: list<list<string>>, groups?: list<array{title: string, rows: list<list<string>>}>}  $data
     * @param  list<array{label: string, value: string, class: string}>  $totals
     */
    private function grid(array $data, array $totals = []): string
    {
        $columns = $data['columns'] ?? [];
        $span = max(count($columns), 2);

        /*
         * ALIGNMENT IS A PROPERTY OF THE COLUMN, NOT OF EACH CELL.
         *
         * It used to be decided twice and differently: the heading by its
         * position (the last one, always) and the cells by sniffing each
         * value. On a table whose final column held dates that printed a
         * right-aligned "Added" over a stack of left-aligned dates — and a
         * heading that does not sit over its own column is the small kind of
         * wrongness that makes a whole printed table look untrustworthy.
         */
        $numeric = $this->numericColumns($this->allRows($data), $span);

        $head = '';

        foreach ($columns as $index => $column) {
            $head .= '<th'.(($numeric[$index] ?? false) ? ' class="num"' : '').'>'
                .$this->e((string) $column).'</th>';
        }

        $head = $head === '' ? '' : '<thead><tr>'.$head.'</tr></thead>';

        $body = '';
        $index = 0;

        $rowHtml = function (array $row) use (&$index, $numeric): string {
            $cells = '';

            foreach (array_values($row) as $column => $cell) {
                $cells .= '<td'.(($numeric[$column] ?? false) ? ' class="num"' : '').'>'
                    .$this->e((string) $cell).'</td>';
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

        /*
         * The figure sits under the amounts and the label to its left, with
         * the columns neither uses left unruled — so the totals read as
         * hanging off the table rather than as more line items.
         *
         * THE LABEL SPANS HALF THE TABLE, and that is not cosmetic. In a
         * single column, "Total Amount" set bold at the display size demanded
         * its whole width from one heading — which on a tax invoice blew the
         * Tax column out to three times the width of the figures in it, and on
         * an 80mm till roll pushed the Amount column clean off the paper.
         * Spread across several columns nobody has to give up anything.
         */
        $label = max(1, (int) ceil(($span - 1) / 2));
        $void = $span - 1 - $label;

        foreach ($totals as $total) {
            $body .= '<tr'.($total['class'] !== '' ? ' class="'.$total['class'].'"' : '').'>'
                .($void > 0 ? '<td class="void" colspan="'.$void.'"></td>' : '')
                .'<td class="tk" colspan="'.$label.'">'.$this->e($total['label']).'</td>'
                .'<td class="tv">'.$this->e($total['value']).'</td></tr>';
        }

        return '<table class="grid">'.$head.'<tbody>'.$body.'</tbody></table>';
    }

    /**
     * Every data row, flat or grouped, for working out column alignment.
     *
     * @param  array<string, mixed>  $data
     * @return list<list<string>>
     */
    private function allRows(array $data): array
    {
        if (($data['groups'] ?? []) === []) {
            return array_values($data['rows'] ?? []);
        }

        $rows = [];

        foreach ($data['groups'] as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Which columns hold nothing but numbers.
     *
     * EVERY non-empty value has to be numeric, not most of them. A column of
     * amounts with one "N/A" in it is still a column of amounts and should
     * line up on the right; a column of dates with one bare year in it is not,
     * and half-aligning it would be worse than leaving it alone.
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
                $cell = trim((string) (array_values($row)[$column] ?? ''));

                if ($cell === '') {
                    continue;
                }

                $seen++;

                if (is_numeric(str_replace(['₹', ',', ' ', '%'], '', $cell))) {
                    $numbers++;
                }
            }

            /*
             * The first column is never pushed right. It is either a row
             * number or the name of the thing being charged for — a label for
             * its row rather than a figure anybody compares against the row
             * above, and both read better hard against the left margin.
             */
            $numeric[$column] = $column > 0 && $seen > 0 && $numbers === $seen;
        }

        return $numeric;
    }

    /**
     * How the bill was settled — and the square that opens it again.
     *
     * Absent entirely when nothing has been tendered and nothing is owed: an
     * empty "Payment information" card over a document that is not a bill is a
     * card that answers nothing.
     *
     * @param  array<string, string>  $values
     */
    private function settlement(string $documentType, array $values, bool $receipt): string
    {
        $paid = (string) ($values['amount_paid'] ?? '');

        $rows = $this->pairs([
            'Amount Paid' => $paid,
            'Payment Mode' => $values['payment_method'] ?? '',
            'Transaction Ref.' => $values['payment_reference'] ?? '',
            'Paid On' => $values['payment_date'] ?? '',
            /* The figure written out, which is what makes a receipt hard to
               alter after it has been handed over. */
            'Amount in Words' => $this->words($this->amount($paid !== '' ? $paid : ($values['total'] ?? ''))),
        ]);

        if (trim($rows) === '') {
            return '';
        }

        /*
         * THE QR SQUARE encodes the document's own number rather than a URL:
         * this software is deployed per clinic, and there is no address here
         * guaranteed to resolve from a patient's phone. A number a counter can
         * search for works on every install; a link to a host that may not be
         * public works on none of them reliably.
         *
         * Absent on a till roll — 80mm of thermal paper has no room, and the
         * receipt already carries the number in text.
         */
        $reference = trim((string) ($values['invoice_number'] ?? $values['document_number'] ?? ''));

        $qr = ! $receipt && $reference !== ''
            ? $this->qr->dataUri($reference, 220)
            : null;

        $content = $qr === null
            ? $rows
            : '<table><tr>'
                .'<td style="vertical-align:top;padding-right:5mm">'.$rows.'</td>'
                .'<td style="width:20mm;vertical-align:top;text-align:center">'
                .'<img src="'.$qr.'" style="width:17mm;height:17mm">'
                .'<div class="qr-cap">Scan to verify<br>'.$this->e($reference).'</div>'
                .'</td></tr></table>';

        return $this->card('Payment information', 'credit-card', $content, false, true)
            .$this->signatures($documentType, $values, $receipt);
    }

    /**
     * Who handed it over, and who the clinic signs as.
     *
     * Two boxes rather than one rule at the foot of the page: a receipt that
     * names the person who took the money is a receipt somebody can come back
     * about, and that name is not the same as the clinic's own signature.
     *
     * @param  array<string, string>  $values
     */
    private function signatures(string $documentType, array $values, bool $receipt): string
    {
        $label = self::HANDOVER[$documentType] ?? null;

        if ($receipt || $label === null) {
            return '';
        }

        $by = trim((string) ($values['generated_by'] ?? ''));

        /*
         * BOTH BOXES THE SAME HEIGHT, set on the body rather than left to the
         * content. One holds two lines of name and the other a hand's width of
         * blank paper, so left to themselves they set as a tall box beside a
         * short one — which reads as a layout that ran out rather than as a
         * pair of things to sign.
         *
         * A SPACER, not `vertical-align: bottom`: dompdf ignores the alignment
         * on a cell whose height is larger than its content, which put the
         * signature rule across the TOP of its box with the space to sign in
         * underneath it.
         */
        $left = $this->card($label, 'user',
            '<div class="sign-box">'
            .'<div class="sign-name">'.$this->e($by !== '' ? $by : '—').'</div>'
            .'<div class="sign-role">'.$this->e($values['branch_name'] ?? 'Counter').'</div>'
            .'</div>',
            false, true);

        $right = $this->card('Authorised signature', 'writing-sign',
            '<div class="sign-box">'
            .'<div class="sign-room"></div>'
            .'<div class="sign-line">Authorised signatory</div>'
            .'</div>',
            false, true);

        return '<table><tr>'
            .'<td style="width:50%;padding-right:2.5mm">'.$left.'</td>'
            .'<td style="width:50%;padding-left:2.5mm">'.$right.'</td>'
            .'</tr></table>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     */
    private function footer(array $config, array $values): string
    {
        $footer = Arr::get($config, 'footer', []);
        $out = [];

        /*
         * Terms get a box of their own, separate from the footer band.
         *
         * They are the one part of a document somebody may have to point at
         * later — a refund policy, a validity period — and running them into
         * the same centred grey paragraph as "Thank you" is how a clinic loses
         * that argument.
         */
        $terms = trim($this->fill((string) Arr::get($footer, 'terms', ''), $values));

        if ($terms !== '') {
            $out[] = '<div class="terms">'.nl2br($this->e($terms)).'</div>';
        }

        $lines = [];

        foreach ((array) Arr::get($footer, 'lines', []) as $line) {
            $filled = trim($this->fill((string) $line, $values));

            if ($filled !== '') {
                $lines[] = $filled;
            }
        }

        /*
         * The FIRST footer line is the clinic's own — set in the accent and
         * bold, with the rest under it as small print. A band of five grey
         * lines at the same weight is a band nobody reads any of.
         */
        if ($lines !== []) {
            $headline = array_shift($lines);

            $out[] = '<div class="fband">'
                .'<div class="h">'.Icons::span('shield-check').' '.$this->e($headline).'</div>'
                .($lines !== [] ? '<div class="s">'.implode('<br>', array_map($this->e(...), $lines)).'</div>' : '')
                .'</div>';
        }

        return implode("\n", $out);
    }

    /**
     * Where this copy came from — printed under the sheet, not on it.
     *
     * ALWAYS. A printed medical document that cannot say when it was produced
     * or by whom is a document nobody can place afterwards, and two copies of
     * the same bill are otherwise indistinguishable.
     *
     * Outside the card on purpose: it is a note about the printing rather than
     * part of the bill, and inside the frame it read as one more thing the
     * clinic was telling the patient.
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

    /** A formatted money string back to a number. "₹1,240.50" → 1240.5 */
    private function amount(string $money): float
    {
        $digits = preg_replace('/[^0-9.]/', '', $money);

        return $digits === '' || $digits === null ? 0.0 : (float) $digits;
    }

    /**
     * A figure written out, Indian style — lakh and crore, not million.
     *
     * "Rupees One Lakh Twenty Thousand Only" is what a bill in India says, and
     * a receipt that spells the same number as "One Hundred Twenty Thousand"
     * is one a patient will query at the counter.
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
