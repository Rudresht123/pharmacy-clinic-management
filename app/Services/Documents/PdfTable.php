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

    private const HEAD_FILL = [0.91, 0.941, 1.0];

    private const HEAD_RULE = [0.78, 0.843, 0.961];

    private const RULE = [0.929, 0.941, 0.961];

    private const ZEBRA = [0.98, 0.984, 0.992];

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
     * @param  array{organization: string, title: string, meta?: list<string>, notes?: list<array{text: string, tone?: string}>, footer?: string}  $heading
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
        $y = $this->headerRow($y);

        $floor = $this->canvas->get_height() - self::MARGIN_BOTTOM;
        $lineHeight = $this->size * self::LINE;
        $index = 0;

        foreach ($rows as $row) {
            $cells = [];
            $lines = 1;

            foreach ($this->columns as $i => $column) {
                $font = $column['bold'] ? $this->bold : $this->regular;
                $cells[$i] = $this->fit((string) ($row['cells'][$i] ?? ''), $column['width'] - 2 * self::PAD_X, $font, $this->size);
                $lines = max($lines, count($cells[$i]));
            }

            $height = $lines * $lineHeight + 2 * self::PAD_Y;

            // The header row again at the top of every page a row spills onto.
            if ($y + $height > $floor) {
                $this->canvas->new_page();
                $y = $this->headerRow(self::MARGIN_TOP);
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

            $y += $height;
            $this->canvas->line(self::MARGIN_X, $y, self::MARGIN_X + $this->tableWidth(), $y, self::RULE, 0.5);
            $index++;
        }

        if ($index === 0) {
            $this->canvas->text(self::MARGIN_X + self::PAD_X, $y + 10, $empty, $this->regular, 9, self::SOFT);
        } elseif ($totals !== null && ($cells = $totals()) !== null) {
            $height = $lineHeight + 2 * (self::PAD_Y + 1);

            if ($y + $height > $floor) {
                $this->canvas->new_page();
                $y = $this->headerRow(self::MARGIN_TOP);
            }

            $this->canvas->filled_rectangle(self::MARGIN_X, $y, $this->tableWidth(), $height, self::HEAD_FILL);
            $this->canvas->line(self::MARGIN_X, $y, self::MARGIN_X + $this->tableWidth(), $y, self::HEAD_RULE, 0.8);

            foreach ($this->columns as $i => $column) {
                $text = $this->fit((string) ($cells[$i] ?? ''), $column['width'] - 2 * self::PAD_X, $this->bold, $this->size, 1)[0] ?? '';
                $this->cellText($column, $text, $y + self::PAD_Y + 1, $this->bold, $this->size, self::BRAND);
            }
        }

        $this->footer($heading['footer'] ?? $heading['organization'].' · '.$heading['title']);

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

    /** The organization, the title and who asked, then any notes — first page only. */
    private function heading(array $heading, float $y): float
    {
        $right = self::MARGIN_X + $this->tableWidth();

        $this->canvas->text(self::MARGIN_X, $y, mb_strtoupper($heading['organization']), $this->bold, 8, self::BRAND);
        $this->canvas->text(self::MARGIN_X, $y + 12, $heading['title'], $this->bold, 16, self::INK);

        foreach (array_values($heading['meta'] ?? []) as $n => $line) {
            $width = $this->canvas->get_text_width($line, $this->regular, 7.5);
            $this->canvas->text($right - $width, $y + 8 + $n * 11, $line, $this->regular, 7.5, self::SOFT);
        }

        $y += 40;

        foreach ($heading['notes'] ?? [] as $note) {
            [$fill, $rule, $ink] = self::TONES[$note['tone'] ?? 'info'] ?? self::TONES['info'];

            $lines = $this->fit($note['text'], $this->tableWidth() - 16, $this->regular, 7.5, 3);
            $height = count($lines) * 7.5 * self::LINE + 10;

            $this->canvas->filled_rectangle(self::MARGIN_X, $y, $this->tableWidth(), $height, $fill);
            $this->canvas->rectangle(self::MARGIN_X, $y, $this->tableWidth(), $height, $rule, 0.6);

            foreach ($lines as $n => $line) {
                $this->canvas->text(self::MARGIN_X + 8, $y + 3.5 + $n * 7.5 * self::LINE, $line, $this->regular, 7.5, $ink);
            }

            $y += $height + 6;
        }

        return $y;
    }

    private function headerRow(float $y): float
    {
        $size = $this->size - 0.5;
        $lineHeight = $size * self::LINE;

        // A label too long for its column takes a second line rather than an ellipsis.
        $labels = array_map(
            fn (array $column) => $this->fit($column['label'], $column['width'] - 2 * self::PAD_X, $this->bold, $size),
            $this->columns,
        );
        $height = max(1, ...array_map('count', $labels)) * $lineHeight + 2 * (self::PAD_Y + 1);

        $this->canvas->filled_rectangle(self::MARGIN_X, $y, $this->tableWidth(), $height, self::HEAD_FILL);

        foreach ($this->columns as $i => $column) {
            foreach ($labels[$i] as $n => $label) {
                $this->cellText($column, $label, $y + self::PAD_Y + 1 + $n * $lineHeight, $this->bold, $size, self::BRAND);
            }
        }

        $y += $height;
        $this->canvas->line(self::MARGIN_X, $y, self::MARGIN_X + $this->tableWidth(), $y, self::HEAD_RULE, 0.8);

        return $y;
    }

    /** What the register is, bottom left, and "3 / 41" bottom right, on every page. */
    private function footer(string $label): void
    {
        $y = $this->canvas->get_height() - 22;
        $right = self::MARGIN_X + $this->tableWidth();

        $this->canvas->page_text(self::MARGIN_X, $y, $label, $this->regular, 6.5, self::SOFT);
        $this->canvas->page_script(function (int $page, int $pages) use ($y, $right) {
            $text = "{$page} / {$pages}";
            $width = $this->canvas->get_text_width($text, $this->regular, 6.5);

            $this->canvas->text($right - $width, $y, $text, $this->regular, 6.5, self::SOFT);
        });
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
