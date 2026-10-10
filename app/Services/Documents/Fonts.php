<?php

namespace App\Services\Documents;

/**
 * The typefaces printed documents are set in, bundled under
 * `resources/fonts/documents` (SIL Open Font License, see the OFL files).
 *
 * Poppins carries the rupee sign and Devanagari, which is what the earlier
 * DejaVu-only setup existed to guarantee. dompdf knows two weights per family,
 * so each extra weight is registered as a family of its own.
 *
 * Registration can fail on a read-only vendor tree; every stylesheet names
 * DejaVu after these, so a document then prints in DejaVu rather than not at
 * all. See PdfRenderer.
 */
class Fonts
{
    /** Regular, with SemiBold standing in as its bold. */
    public const SANS = 'poppins';

    public const SANS_MEDIUM = 'poppinsmedium';

    /** The full Bold, for the clinic's name and the document's title. */
    public const SANS_HEAVY = 'poppinsheavy';

    /** The handwritten thank-you along the footer. */
    public const SCRIPT = 'greatvibes';

    public static function directory(): string
    {
        return resource_path('fonts/documents');
    }

    /**
     * @return list<array{family: string, style: string, weight: string, file: string}>
     */
    public static function all(): array
    {
        $dir = self::directory();

        return [
            ['family' => self::SANS, 'style' => 'normal', 'weight' => 'normal', 'file' => $dir.'/Poppins-Regular.ttf'],
            ['family' => self::SANS, 'style' => 'normal', 'weight' => 'bold', 'file' => $dir.'/Poppins-SemiBold.ttf'],
            ['family' => self::SANS, 'style' => 'italic', 'weight' => 'normal', 'file' => $dir.'/Poppins-Italic.ttf'],
            ['family' => self::SANS_MEDIUM, 'style' => 'normal', 'weight' => 'normal', 'file' => $dir.'/Poppins-Medium.ttf'],
            ['family' => self::SANS_HEAVY, 'style' => 'normal', 'weight' => 'normal', 'file' => $dir.'/Poppins-Bold.ttf'],
            ['family' => self::SCRIPT, 'style' => 'normal', 'weight' => 'normal', 'file' => $dir.'/GreatVibes-Regular.ttf'],
        ];
    }
}
