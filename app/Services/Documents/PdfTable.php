<?php

namespace App\Services\Documents;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * A long table drawn straight onto PDF pages — a register, every row of it.
 *
 * PdfRenderer lays HTML out, which is right for a bill and wrong for ten
 * thousand patients: dompdf holds every cell of a document in memory while it
 * lays the table out — about half a megabyte a row — so a whole register would
 * need gigabytes and minutes. This skips layout. The columns are fixed, each
 * row is measured and drawn as it arrives, and nothing is kept but the page
 * content already written; a register of any length costs the same per row,
 * and the rows can come straight from a lazy query.
 *
 * Same engine and typeface as the documents PdfRenderer makes — dompdf's own
 * PDF writer and DejaVu Sans — so a name in any script and the rupee sign
 * print here as they do on a bill.
 */
class PdfTable
{
    /** Landscape A4, in points. */
    private const MARGIN_X = 34.0;

    private const MARGIN_TOP = 36.0;

    private const MARGIN_BOTTOM = 34.0;

    private const PAD_X = 4.0;

    private const PAD_Y = 3.5;

    /**
     * Body sizes to try, largest first. A table of many columns steps down
     * until every column's longest word fits on its line unbroken — a phone
     * number split across two lines is a wrong number.
     */
    private const SIZES = [7.5, 7.0, 6.5, 6.0];

    /** How many rows are read ahead to size the columns from what is in them. */
    private const SAMPLE = 300;

    /** No one unbreakable word may claim more of the row than this; past it, it breaks. */
    private const WORD_SHARE = 0.16;

    /** A value narrower than this share of the row (measured at 10pt) stays on one line. */
    private const KEEP_WHOLE = 0.13;

    private const LINE = 1.3;

    /** A cell wraps to this many lines, and the last one ends in an ellipsis. */
    private const MAX_LINES = 3;

    private const INK = [0.122, 0.161, 0.216];

    private const SOFT = [0.42, 0.447, 0.502];

    private const MUTED = [0.612, 0.639, 0.686];

    private const BRAND = [0.118, 0.227, 0.541];

    private const WHITE = [1.0, 1.0, 1.0];

    /** The summary boxes beside the title. */
    private const PANEL = [0.965, 0.973, 0.992];

    private const PANEL_RULE = [0.871, 0.902, 0.953];

    /** A filter, as a pill under the title. */
    private const CHIP = [0.925, 0.945, 0.988];

    /** The table's own rounded frame, on every page. */
    private const FRAME = [0.839, 0.871, 0.925];

    private const RULE = [0.929, 0.941, 0.961];

    private const ZEBRA = [0.973, 0.98, 0.992];

    /** The totals row under the money. */
    private const TOTAL_FILL = [0.925, 0.945, 0.988];

    /** The frame's and the header's corners. */
    private const RADIUS = 4.0;

    /** A note's fill, rule and ink, by tone. */
    private const TONES = [
        'info' => [[0.953, 0.969, 0.992], [0.894, 0.925, 0.973], [0.216, 0.255, 0.318]],
        'warning' => [[1.0, 0.969, 0.902], [0.992, 0.894, 0.69], [0.573, 0.251, 0.055]],
    ];

    private CPDF $canvas;

    private string $regular;

    private string $bold;

    /** @var list<array{label: string, x: float, width: float, align: string, bold: bool}> */
    private array $columns = [];

    /** @var array<string, float> text widths already measured, by font, size and text */
    private array $measured = [];

    /** The body size this table settled on; the header is half a point under it. */
    private float $size = 7.5;

    /**
     * The first page opens like a report: the organisation, the title and
     * what it is, summary boxes on the right ("772 visits", when and by whom),
     * and the filters as pills. The table sits in a rounded frame under a
     * deep-blue header row, on every page; a page after the first carries a
     * running title, and every page a footer — what it is, whether it is
     * confidential, and "Page 3 of 41".
     *
     * @param  array{organization: string, title: string, eyebrow?: string|null, stats?: list<array{value: string, label: string}>, chips?: list<string>, meta?: list<string>, notes?: list<array{text: string, tone?: string}>, footer?: string, confidential?: bool}  $heading
     * @param  list<array{label: string, width: float, align?: string, bold?: bool, nowrap?: bool}>  $columns  each width a share of the row; the shares need not add up to anything; `nowrap` keeps a value on one line
     * @param  iterable<array{cells: list<string|int|float|null>, muted?: bool}>  $rows
     * @param  (callable(): (list<string>|null))|null  $totals  a closing row in bold, asked for after every row is drawn
     *                                                          — so it can add up rows that were streamed
     * @return string the PDF bytes
     */
    public function render(array $heading, array $columns, iterable $rows, string $empty = 'Nothing to list.', ?callable $totals = null): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->setChroot([Fonts::directory()]);

        $dompdf = new Dompdf($options);

        $this->canvas = new CPDF('a4', 'landscape', $dompdf);
        [$this->regular, $this->bold] = $this->typeface($dompdf);

        /*
         * The first few hundred rows are read before anything is drawn, and
         * the columns sized from them — then drawn first, and the rest
         * streamed after. A dataset's declared shares cannot know that this
         * branch's invoice numbers run to twenty-two characters.
         */
        $source = (function () use ($rows) {
            yield from $rows;
        })();

        $sample = [];

        while ($source->valid() && count($sample) < self::SAMPLE) {
            $sample[] = $source->current();
            $source->next();
        }

        $this->layColumns($columns, $sample);

        $rows = (function () use ($sample, $source) {
            yield from $sample;

            while ($source->valid()) {
                yield $source->current();
                $source->next();
            }
        })();

        $this->canvas->add_info('Title', $heading['title']);

        $y = $this->heading($heading, self::MARGIN_TOP);
        $top = $y;
        $y = $this->headerRow($y);

        $floor = $this->canvas->get_height() - self::MARGIN_BOTTOM;
        $lineHeight = $this->size * self::LINE;
        $index = 0;

        // Onto a fresh page: close this page's frame, then the running title and the header row again.
        $turn = function (float $y) use ($heading, &$top): float {
            $this->frame($top, $y);
            $this->canvas->new_page();
            $top = $this->runningTitle($heading, self::MARGIN_TOP);

            return $this->headerRow($top);
        };

        foreach ($rows as $row) {
            $cells = [];
            $lines = 1;

            foreach ($this->columns as $i => $column) {
                $font = $column['bold'] ? $this->bold : $this->regular;
                $cells[$i] = $this->fit((string) ($row['cells'][$i] ?? ''), $column['width'] - 2 * self::PAD_X, $font, $this->size);
                $lines = max($lines, count($cells[$i]));
            }

            $height = $lines * $lineHeight + 2 * self::PAD_Y;

            if ($y + $height > $floor) {
                $y = $turn($y);
                $index = 0;
            }

            if ($index % 2 === 1) {
                $this->canvas->filled_rectangle(self::MARGIN_X, $y, $this->tableWidth(), $height, self::ZEBRA);
            }

            $ink = ($row['muted'] ?? false) ? self::MUTED : self::INK;

            foreach ($this->columns as $i => $column) {
                $font = $column['bold'] ? $this->bold : $this->regular;

                foreach ($cells[$i] as $n => $text) {
                    $this->cellText($column, $text, $y + self::PAD_Y + $n * $lineHeight, $font, $this->size, $ink);
                }
            }

            // A rule between rows; the frame is the last one's.
            if ($index > 0) {
                $this->canvas->line(self::MARGIN_X + 0.5, $y, self::MARGIN_X + $this->tableWidth() - 0.5, $y, self::RULE, 0.5);
            }

            $y += $height;
            $index++;
        }

        if ($index === 0) {
            $this->canvas->text(self::MARGIN_X + 10, $y + 9, $empty, $this->regular, 8.5, self::SOFT);
            $y += 30;
        } elseif ($totals !== null && ($cells = $totals()) !== null) {
            $height = $lineHeight + 2 * (self::PAD_Y + 1.5);

            if ($y + $height > $floor) {
                $y = $turn($y);
            }

            $this->roundRect(self::MARGIN_X, $y, $this->tableWidth(), $height, [0, 0, self::RADIUS, self::RADIUS], self::TOTAL_FILL);
            $this->canvas->line(self::MARGIN_X, $y, self::MARGIN_X + $this->tableWidth(), $y, self::FRAME, 0.7);

            foreach ($this->columns as $i => $column) {
                $text = $this->fit((string) ($cells[$i] ?? ''), $column['width'] - 2 * self::PAD_X, $this->bold, $this->size, 1)[0] ?? '';
                $this->cellText($column, $text, $y + self::PAD_Y + 1.5, $this->bold, $this->size, self::BRAND);
            }

            $y += $height;
        }

        $this->frame($top, $y);

        $this->footer(
            $heading['footer'] ?? $heading['organization'].' · '.$heading['title'],
            (bool) ($heading['confidential'] ?? false),
        );

        return (string) $this->canvas->output();
    }

    /**
     * Poppins — the documents' own face, which carries Devanagari and the
     * rupee sign — registered as PdfRenderer does; DejaVu Sans when it will
     * not load, rather than no register at all.
     *
     * @return array{0: string, 1: string} the regular and bold font files
     */
    private function typeface(Dompdf $dompdf): array
    {
        $metrics = $dompdf->getFontMetrics();

        try {
            foreach (Fonts::all() as $font) {
                if ($font['family'] === Fonts::SANS && $font['style'] === 'normal') {
                    $metrics->registerFont(['family' => $font['family'], 'style' => 'normal', 'weight' => $font['weight']], $font['file']);
                }
            }

            $regular = $metrics->getFont(Fonts::SANS);
            $bold = $metrics->getFont(Fonts::SANS, 'bold');

            if ($regular && $bold) {
                return [$regular, $bold];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [$metrics->getFont('DejaVu Sans'), $metrics->getFont('DejaVu Sans', 'bold')];
    }

    /**
     * Each column's left edge and width, from what is in it.
     *
     * Three passes. Every column first gets its longest unbroken word —
     * header or sample — so nothing splits mid-word; the body size steps
     * down until that fits the page. What is left goes to the columns still
     * short of fitting their text on one line, in proportion to how short
     * they are. Anything left after that follows the declared shares.
     *
     * @param  list<array{label: string, width: float, align?: string, bold?: bool}>  $columns
     * @param  list<array{cells: list<string|int|float|null>}>  $sample
     */
    private function layColumns(array $columns, array $sample): void
    {
        $table = $this->tableWidth();
        $pad = 2 * self::PAD_X;

        /*
         * Three widths per column, measured once at 10pt (they scale with
         * the size): its longest word, which must never break; its longest
         * SHORT value whole — a date, a name — which should not wrap if the
         * page can help it; and its longest value on one line.
         */
        $word = [];
        $whole = [];
        $line = [];

        foreach (array_values($columns) as $i => $column) {
            $word[$i] = $whole[$i] = $line[$i] = 0.0;
            $font = ($column['bold'] ?? false) ? $this->bold : $this->regular;

            $texts = [[$column['label'], $this->bold]];

            foreach ($sample as $row) {
                $texts[] = [(string) ($row['cells'][$i] ?? ''), $font];
            }

            foreach ($texts as [$text, $face]) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $text));

                if ($text === '') {
                    continue;
                }

                $full = $this->measure($text, $face, 10);
                $line[$i] = max($line[$i], $full);
                $longest = 0.0;

                foreach (explode(' ', $text) as $piece) {
                    $longest = max($longest, $this->measure($piece, $face, 10));
                }

                // A column that never wraps — a date, an amount — counts its whole value as one word.
                $unbroken = ($column['nowrap'] ?? false) ? $full : $longest;

                $word[$i] = max($word[$i], $unbroken);
                $whole[$i] = max($whole[$i], $full <= $table * self::KEEP_WHOLE ? $full : $unbroken);
            }
        }

        $scaled = fn (array $widths, float $size, float $cap) => array_map(
            fn (float $width) => min($width * $size / 10, $table * $cap) + $pad,
            $widths,
        );

        /*
         * The largest size at which every short value fits whole — names and
         * dates on one line. A wide table that cannot manage that even at the
         * smallest size gets the smallest: a smaller letter wraps less, and
         * fewer wrapped rows read better than larger letters stacked three high.
         */
        $this->size = self::SIZES[array_key_last(self::SIZES)];

        foreach (self::SIZES as $size) {
            if (array_sum($scaled($whole, $size, self::WORD_SHARE)) <= $table) {
                $this->size = $size;

                break;
            }
        }

        $widths = $scaled($word, $this->size, self::WORD_SHARE);
        $spare = $table - array_sum($widths);

        // Too many columns for the page even at the smallest size: everybody shrinks alike.
        if ($spare < 0) {
            $widths = array_map(fn (float $width) => $width * $table / array_sum($widths), $widths);
            $spare = 0.0;
        }

        // Then short values whole, then long values on one line, as far as the page goes.
        foreach ([$scaled($whole, $this->size, self::WORD_SHARE), $scaled($line, $this->size, 0.4)] as $wanted) {
            $short = [];

            foreach ($widths as $i => $width) {
                $short[$i] = max(0.0, $wanted[$i] - $width);
            }

            $shortfall = array_sum($short);

            if ($spare <= 0 || $shortfall <= 0) {
                continue;
            }

            $give = min($spare, $shortfall);

            foreach ($widths as $i => $width) {
                $widths[$i] = $width + $give * $short[$i] / $shortfall;
            }

            $spare -= $give;
        }

        if ($spare > 0) {
            $shares = array_sum(array_column($columns, 'width')) ?: 1;

            foreach (array_values($columns) as $i => $column) {
                $widths[$i] += $spare * $column['width'] / $shares;
            }
        }

        $x = self::MARGIN_X;

        foreach (array_values($columns) as $i => $column) {
            $this->columns[] = [
                'label' => $column['label'],
                'x' => $x,
                'width' => $widths[$i],
                'align' => $column['align'] ?? 'left',
                'bold' => (bool) ($column['bold'] ?? false),
            ];

            $x += $widths[$i];
        }
    }

    /**
     * The first page's head: a band of the brand along the top edge; the
     * organisation, the title and what it is on the left; the summary boxes
     * on the right; then the filters as pills, and any notes.
     */
    private function heading(array $heading, float $y): float
    {
        $left = self::MARGIN_X;
        $right = self::MARGIN_X + $this->tableWidth();

        $this->canvas->filled_rectangle(0, 0, $this->canvas->get_width(), 4, self::BRAND);

        // The boxes first, right to left, so the title knows how much room it has.
        $boxes = [];
        $x = $right;

        foreach (array_reverse($heading['stats'] ?? []) as $stat) {
            $width = max($this->measure($stat['value'], $this->bold, 11), $this->measure($stat['label'], $this->regular, 6.5)) + 22;
            $x -= $width;
            $boxes[] = [$x, $width, $stat];
            $x -= 6;
        }

        // An older caller's plain lines, right-aligned, where it sent no boxes.
        foreach (array_values($boxes === [] ? ($heading['meta'] ?? []) : []) as $n => $line) {
            $width = $this->measure($line, $this->regular, 7.5);
            $this->canvas->text($right - $width, $y + 8 + $n * 11, $line, $this->regular, 7.5, self::SOFT);
        }

        $room = ($boxes === [] ? $right - 220 : $x) - $left - 12;

        $this->canvas->text($left, $y, mb_strtoupper($heading['organization']), $this->bold, 7.5, self::BRAND, 0.0, 0.9);
        $this->canvas->text($left, $y + 11, $this->fit($heading['title'], $room, $this->bold, 17, 1)[0] ?? '', $this->bold, 17, self::INK);

        if (($heading['eyebrow'] ?? null) !== null) {
            $this->canvas->text($left, $y + 39, $heading['eyebrow'], $this->regular, 7.5, self::SOFT);
        }

        foreach ($boxes as [$bx, $width, $stat]) {
            $this->roundRect($bx, $y - 3, $width, 40, [6, 6, 6, 6], self::PANEL);
            $this->roundRect($bx, $y - 3, $width, 40, [6, 6, 6, 6], self::PANEL_RULE, 0.6);
            $this->canvas->text($bx + 11, $y + 5, $stat['value'], $this->bold, 11, self::INK);
            $this->canvas->text($bx + 11, $y + 22, $stat['label'], $this->regular, 6.5, self::SOFT);
        }

        $y += 60;

        $y = $this->chips($heading['chips'] ?? null, $y);

        foreach ($heading['notes'] ?? [] as $note) {
            [$fill, $rule, $ink] = self::TONES[$note['tone'] ?? 'info'] ?? self::TONES['info'];

            $lines = $this->fit($note['text'], $this->tableWidth() - 20, $this->regular, 7.5, 3);
            $height = count($lines) * 7.5 * self::LINE + 11;

            $this->roundRect($left, $y, $this->tableWidth(), $height, [5, 5, 5, 5], $fill);
            $this->roundRect($left, $y, $this->tableWidth(), $height, [5, 5, 5, 5], $rule, 0.6);

            foreach ($lines as $n => $line) {
                $this->canvas->text($left + 10, $y + 4 + $n * 7.5 * self::LINE, $line, $this->regular, 7.5, $ink);
            }

            $y += $height + 8;
        }

        return $y;
    }

    /**
     * "FILTERS  [Visit date 01 Nov – 08 Nov 2026]  [At MG Road]" — one pill a
     * filter, wrapping onto a second line when they run out of room. No list
     * at all (an older caller) draws nothing; an empty one says all records.
     *
     * @param  list<string>|null  $chips
     */
    private function chips(?array $chips, float $y): float
    {
        if ($chips === null) {
            return $y;
        }

        $left = self::MARGIN_X;
        $right = self::MARGIN_X + $this->tableWidth();
        $label = 'FILTERS';
        $start = $left + $this->measure($label, $this->bold, 6.5) + mb_strlen($label) * 0.7 + 10;
        $height = 15.0;

        $this->canvas->text($left, $y + 4.5, $label, $this->bold, 6.5, self::SOFT, 0.0, 0.7);

        $x = $start;

        foreach ($chips === [] ? ['All records'] : $chips as $chip) {
            $text = $this->fit($chip, $right - $start - 14, $this->regular, 7, 1)[0] ?? '';
            $width = $this->measure($text, $this->regular, 7) + 16;

            if ($x > $start && $x + $width > $right) {
                $x = $start;
                $y += $height + 5;
            }

            $this->roundRect($x, $y, $width, $height, array_fill(0, 4, $height / 2), self::CHIP);
            $this->canvas->text($x + 8, $y + 3.7, $text, $this->regular, 7, self::BRAND);

            $x += $width + 5;
        }

        return $y + $height + 12;
    }

    /** A page after the first: whose register, and which, above the header row. */
    private function runningTitle(array $heading, float $y): float
    {
        $organization = mb_strtoupper($heading['organization']);
        $width = $this->measure($organization, $this->bold, 6.5) + mb_strlen($organization) * 0.7;

        $this->canvas->text(self::MARGIN_X, $y, $organization, $this->bold, 6.5, self::BRAND, 0.0, 0.7);
        $this->canvas->text(self::MARGIN_X + $width + 8, $y - 0.5, $heading['title'], $this->bold, 7.5, self::INK);

        return $y + 16;
    }

    /** Deep blue, rounded at the top, the labels in white. */
    private function headerRow(float $y): float
    {
        $size = $this->size - 0.5;
        $lineHeight = $size * self::LINE;

        // A label too long for its column takes a second line rather than an ellipsis.
        $labels = array_map(
            fn (array $column) => $this->fit($column['label'], $column['width'] - 2 * self::PAD_X, $this->bold, $size),
            $this->columns,
        );
        $height = max(1, ...array_map('count', $labels)) * $lineHeight + 2 * (self::PAD_Y + 2);

        $this->roundRect(self::MARGIN_X, $y, $this->tableWidth(), $height, [self::RADIUS, self::RADIUS, 0, 0], self::BRAND);

        foreach ($this->columns as $i => $column) {
            foreach ($labels[$i] as $n => $label) {
                $this->cellText($column, $label, $y + self::PAD_Y + 2 + $n * $lineHeight, $this->bold, $size, self::WHITE);
            }
        }

        return $y + $height;
    }

    /** The table's rounded outline, from its header to its last row on this page. */
    private function frame(float $top, float $bottom): void
    {
        $this->roundRect(self::MARGIN_X, $top, $this->tableWidth(), $bottom - $top, array_fill(0, 4, self::RADIUS), self::FRAME, 0.7);
    }

    /**
     * On every page, under a hairline: what the register is on the left,
     * "Confidential" in the middle when it holds people's records, and
     * "Page 3 of 41" on the right.
     */
    private function footer(string $label, bool $confidential): void
    {
        $height = $this->canvas->get_height();
        $y = $height - 22;
        $left = self::MARGIN_X;
        $right = self::MARGIN_X + $this->tableWidth();

        $this->canvas->page_line($left, $y - 6, $right, $y - 6, self::RULE, 0.6);
        $this->canvas->page_text($left, $y, $label, $this->regular, 6.5, self::SOFT);

        if ($confidential) {
            $notice = 'Confidential — contains patient and clinic records';
            $width = $this->measure($notice, $this->regular, 6.5);
            $this->canvas->page_text(($left + $right - $width) / 2, $y, $notice, $this->regular, 6.5, self::SOFT);
        }

        $this->canvas->page_script(function (int $page, int $pages) use ($y, $right) {
            $text = "Page {$page} of {$pages}";
            $width = $this->canvas->get_text_width($text, $this->regular, 6.5);

            $this->canvas->text($right - $width, $y, $text, $this->regular, 6.5, self::SOFT);
        });
    }

    /**
     * A rectangle with rounded corners, filled or outlined, drawn as a
     * polygon — the canvas has no rounded rectangle of its own.
     *
     * @param  array{0: float|int, 1: float|int, 2: float|int, 3: float|int}  $radii  top-left, top-right, bottom-right, bottom-left
     * @param  list<float>  $color
     * @param  float|null  $stroke  a line width to outline; null fills
     */
    private function roundRect(float $x, float $y, float $width, float $height, array $radii, array $color, ?float $stroke = null): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $limit = min($width, $height) / 2;
        [$tl, $tr, $br, $bl] = array_map(fn ($radius) => min((float) $radius, $limit), $radii);
        $points = [];

        // Each corner's arc, clockwise from the left edge; the page's y runs downwards.
        $corner = function (float $cx, float $cy, float $radius, float $from) use (&$points) {
            if ($radius <= 0) {
                array_push($points, $cx, $cy);

                return;
            }

            for ($step = 0; $step <= 6; $step++) {
                $angle = deg2rad($from + 15 * $step);
                array_push($points, $cx + $radius * cos($angle), $cy + $radius * sin($angle));
            }
        };

        $corner($x + $tl, $y + $tl, $tl, 180);
        $corner($x + $width - $tr, $y + $tr, $tr, 270);
        $corner($x + $width - $br, $y + $height - $br, $br, 0);
        $corner($x + $bl, $y + $height - $bl, $bl, 90);

        $this->canvas->polygon($points, $color, $stroke, [], $stroke === null);
    }

    private function cellText(array $column, string $text, float $y, string $font, float $size, array $ink): void
    {
        if ($text === '') {
            return;
        }

        $x = $column['x'] + self::PAD_X;

        if ($column['align'] === 'right') {
            $x = $column['x'] + $column['width'] - self::PAD_X - $this->canvas->get_text_width($text, $font, $size);
        }

        $this->canvas->text($x, $y, $text, $font, $size, $ink);
    }

    /**
     * Text as the lines it takes in a width — broken at spaces, a word too
     * long for the column broken where it must be, and an ellipsis where the
     * lines run out.
     *
     * @return list<string>
     */
    private function fit(string $text, float $width, string $font, float $size, int $maxLines = self::MAX_LINES): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        // A hair of slack: a column sized to exactly its text must not lose it to rounding.
        $width += 0.5;

        if ($this->measure($text, $font, $size) <= $width) {
            return [$text];
        }

        $lines = [];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";

            if ($this->measure($candidate, $font, $size) <= $width) {
                $line = $candidate;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            while ($this->measure($word, $font, $size) > $width && count($lines) <= $maxLines) {
                $cut = $this->fits($word, $width, $font, $size);
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }

            $line = $word;

            if (count($lines) > $maxLines) {
                break;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) <= $maxLines) {
            return $lines;
        }

        $lines = array_slice($lines, 0, $maxLines);
        $last = $lines[$maxLines - 1];

        while ($last !== '' && $this->measure($last.'…', $font, $size) > $width) {
            $last = mb_substr($last, 0, -1);
        }

        $lines[$maxLines - 1] = rtrim($last).'…';

        return $lines;
    }

    /** How many of a word's first characters fit in a width — at least one. */
    private function fits(string $word, float $width, string $font, float $size): int
    {
        $count = mb_strlen($word);

        while ($count > 1 && $this->measure(mb_substr($word, 0, $count), $font, $size) > $width) {
            $count--;
        }

        return $count;
    }

    /**
     * A register repeats itself — the same branch, the same dates, the same
     * status down every page — so each width is measured once.
     */
    private function measure(string $text, string $font, float $size): float
    {
        if (count($this->measured) > 50000) {
            $this->measured = [];
        }

        return $this->measured["{$font}|{$size}|{$text}"] ??= $this->canvas->get_text_width($text, $font, $size);
    }

    private function tableWidth(): float
    {
        return $this->canvas->get_width() - 2 * self::MARGIN_X;
    }
}
