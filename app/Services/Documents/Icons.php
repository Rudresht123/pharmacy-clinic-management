<?php

namespace App\Services\Documents;

/**
 * The small pictures on a printed document, as glyphs from an icon font.
 *
 * A FONT, NOT SVG, and not a pile of PNGs. dompdf's SVG support is a separate
 * parser with its own gaps, and a letterhead whose phone icon renders as an
 * empty box on one clinic's install is worse than one with no icons at all. A
 * TrueType glyph goes through exactly the same path as a letter of text: it
 * scales, it takes the colour of its element, and it cannot half-render.
 *
 * The file is the one the web app already ships — `public/vendor/fonts` — so
 * the icon beside "Patient details" on the screen and the icon beside it on
 * the paper are the same drawing, which is the point.
 *
 * REGISTRATION CAN FAIL: dompdf caches a converted copy inside its own package
 * directory, and a deployment with a read-only vendor tree cannot write it.
 * PdfRenderer registers the font and, when it could not, strips the icon spans
 * out of the markup before rendering — an icon is decoration on a document
 * whose real content is the charges, and a row of .notdef boxes across a bill
 * is the one failure mode worth spending code to avoid.
 */
class Icons
{
    /**
     * The family name the font is registered under.
     *
     * NO HYPHEN. Registered as `tabler-icons`, every glyph came out as a
     * .notdef box — dompdf resolved the CSS `font-family` to the default font
     * instead of the registered one. The name here is arbitrary; that it
     * survives dompdf's font lookup is not.
     */
    public const FAMILY = 'tablericons';

    /**
     * Codepoints, copied from `public/vendor/css/tabler-icons.min.css`.
     *
     * A closed list rather than "whatever name the caller passes": a typo in
     * an icon name should print nothing, not a random pictogram from the
     * private use area.
     */
    private const GLYPHS = [
        // letterhead and contact lines
        'map-pin' => 'eae8',
        'phone' => 'eb09',
        'mail' => 'eae5',
        'world' => 'eb54',
        'building-hospital' => 'ea4d',

        // document types — the tile inside the title band
        'user-plus' => 'eb4b',
        'prescription' => 'ef99',
        'clipboard-text' => 'f089',
        'receipt' => 'edfd',
        'pill' => 'ec44',
        'cash' => 'ea55',
        'file-stack' => 'f503',
        'flask' => 'ebd2',
        'stethoscope' => 'edbe',

        // section heads
        'user' => 'eb4d',
        'file-invoice' => 'eb67',
        'credit-card' => 'ea84',
        'writing-sign' => 'ef07',
        'notes' => 'eb6e',
        'report-medical' => 'eecc',
        'test-pipe' => 'eb3a',
        'qrcode' => 'eb11',
        'shield-check' => 'eb22',

        // how the bill was settled
        'circle-check' => 'ea67',
        'clock' => 'ea70',
        'circle-x' => 'ea6a',
    ];

    public static function file(): string
    {
        return public_path('vendor/fonts/tabler-icons.ttf');
    }

    /**
     * One icon, as a span the renderer can strip if the font did not load.
     *
     * Returns the empty string for an unknown name, so a caller may ask for an
     * icon a document type has no drawing for without guarding every call.
     */
    public static function span(string $name, string $style = ''): string
    {
        $glyph = self::GLYPHS[$name] ?? null;

        if ($glyph === null) {
            return '';
        }

        return '<span class="ti"'.($style !== '' ? ' style="'.$style.'"' : '').'>&#x'.$glyph.';</span>';
    }

    /**
     * Every icon span in a piece of markup, removed.
     *
     * The fallback when the font could not be registered — see the class note.
     */
    public static function strip(string $html): string
    {
        return (string) preg_replace('~<span class="ti"[^>]*>.*?</span>~us', '', $html);
    }
}
