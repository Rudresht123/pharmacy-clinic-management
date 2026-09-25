<?php

namespace App\Services\Documents;

use App\Support\Documents\DocumentTypes;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Arr;

/**
 * HTML in, PDF bytes out. Nothing else.
 *
 * THE ONLY CLASS IN THIS CODEBASE THAT NAMES A PDF LIBRARY. Everything above
 * it — the template, the payload, the markup — is ordinary PHP that can be
 * tested without rendering anything, and swapping dompdf for something else
 * later is this one file.
 *
 * Why dompdf: it is pure PHP and needs nothing installed on the server. The
 * alternative worth having is Browsershot, which renders through headless
 * Chrome and is far better at CSS — but it needs Node and Chromium present on
 * every machine this software is deployed to, and this product is deployed per
 * clinic. That trade-off is why DocumentHtml builds its layout from tables:
 * dompdf has no flexbox and no grid, and a layout that looks right in a
 * browser and collapses in the PDF is worse than one that was never pretty.
 *
 * REMOTE CONTENT IS OFF. `isRemoteEnabled` would let a template's markup pull
 * a URL at render time — which, in a medical document generator that renders
 * whatever a branch typed, is a request made by the server on somebody else's
 * behalf. Logos are passed in as data URIs instead.
 */
class PdfRenderer
{
    /** Paper sizes dompdf understands, by our own key. */
    private const PAPER = [
        DocumentTypes::PAPER_A4 => 'a4',
        DocumentTypes::PAPER_A5 => 'a5',
    ];

    /**
     * @param  array<string, mixed>  $layout  the template's layout section
     * @return string the PDF bytes
     */
    public function render(string $html, array $layout = []): string
    {
        $options = new Options;

        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        // Templates are written by staff, not by the software, and a
        // stylesheet is not a place to run code.
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', Arr::get($layout, 'font_family') === 'serif' ? 'DejaVu Serif' : 'DejaVu Sans');
        // DejaVu carries the rupee sign and Devanagari; the default core fonts
        // do not, and a bill printing "?1,200.00" is not a bill.
        $options->set('defaultMediaType', 'print');

        $dompdf = new Dompdf($options);

        $paper = (string) Arr::get($layout, 'paper', DocumentTypes::PAPER_A4);

        if ($paper === DocumentTypes::PAPER_RECEIPT) {
            /*
             * A till roll: 80mm wide, and long enough for whatever it holds.
             * The height is set generously and dompdf trims to the content —
             * a fixed page height would eject a blank second sheet on a
             * two-line receipt.
             */
            $dompdf->setPaper([0, 0, 226.77, 1700.0]);
        } else {
            $dompdf->setPaper(self::PAPER[$paper] ?? 'a4', 'portrait');
        }

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        if (Arr::get($layout, 'show_page_numbers') && $paper !== DocumentTypes::PAPER_RECEIPT) {
            $this->stampPageNumbers($dompdf);
        }

        return (string) $dompdf->output();
    }

    /**
     * "2 / 5" at the foot of every page.
     *
     * Done here rather than in the markup because a page count is not
     * knowable until the document has been laid out — CSS counters would give
     * the page number and never the total.
     */
    private function stampPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();

        $canvas->page_text(
            $canvas->get_width() / 2 - 20,
            $canvas->get_height() - 28,
            '{PAGE_NUM} / {PAGE_COUNT}',
            null,
            8,
            [0.35, 0.35, 0.35],
        );
    }
}
