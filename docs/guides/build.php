<?php

/**
 * Turns every guide in this folder into its PDF.
 *
 *     php docs/guides/build.php            every *.html here
 *     php docs/guides/build.php receipts   only files whose name contains "receipts"
 *
 * Uses the same dompdf the application prints its documents with, and DejaVu
 * Sans for the same reason: the PDF core fonts are Latin-1 only and print the
 * rupee sign as "?".
 */

use Dompdf\Dompdf;
use Dompdf\Options;

require __DIR__.'/../../vendor/autoload.php';

ini_set('memory_limit', '512M');

$filter = $argv[1] ?? '';

foreach (glob(__DIR__.'/*.html') as $source) {
    if ($filter !== '' && ! str_contains(basename($source), $filter)) {
        continue;
    }

    $options = new Options;
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);

    $pdf = new Dompdf($options);
    $pdf->loadHtml((string) file_get_contents($source), 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();

    // "3 / 9" at the foot of every page.
    $canvas = $pdf->getCanvas();
    $canvas->page_text(
        $canvas->get_width() - 70,
        $canvas->get_height() - 28,
        '{PAGE_NUM} / {PAGE_COUNT}',
        $pdf->getFontMetrics()->getFont('DejaVu Sans'),
        8,
        [0.39, 0.45, 0.55],
    );

    $target = preg_replace('/\.html$/', '.pdf', $source);
    file_put_contents($target, $pdf->output());

    echo basename($target).'  ('.$canvas->get_page_count()." pages)\n";
}
