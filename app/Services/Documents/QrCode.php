<?php

namespace App\Services\Documents;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

/*
 * PINNED TO endroid/qr-code 5.x, AND IT HAS TO STAY THERE.
 *
 * Version 6 requires PHP 8.4. This machine serves the app through Apache on
 * 8.3 while the CLI runs 8.5, so a 6.x install resolves cleanly on the command
 * line and then five-hundreds every page in the browser. composer.json pins
 * config.platform.php to the Apache version so Composer resolves for the
 * runtime that actually serves requests; do not raise it to reach 6.x unless
 * Apache's PHP moves too.
 *
 * The practical difference: 5.x builds through the fluent Builder::create(),
 * 6.x through named constructor arguments. Hence the chained calls below.
 */

/**
 * The square on a printed bill that opens it again.
 *
 * PNG, not SVG. dompdf's SVG support is a separate parser with its own
 * limits, and a QR code that renders as an empty box on one clinic's printer
 * is worse than none at all — a raster the renderer has always handled is
 * the safe choice for something a patient will point a camera at.
 *
 * ERROR CORRECTION IS HIGH on purpose. This gets printed on a thermal or
 * office printer, folded into a pocket and photographed under a waiting-room
 * light; the redundancy is what makes it still scan afterwards.
 */
class QrCode
{
    /**
     * A data URI for the given text, or null if it could not be made.
     *
     * NEVER THROWS. A QR code is decoration on a document whose real content
     * is the charges — failing to draw one must not fail the bill it sits on,
     * which is the whole reason a patient is standing at the counter.
     */
    public function dataUri(string $text, int $size = 120): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $result = Builder::create()
                ->writer(new PngWriter)
                ->data($text)
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size($size)
                /* A quiet zone, or scanners read the paper around it as part
                   of the code. */
                ->margin(4)
                ->build();

            return $result->getDataUri();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
