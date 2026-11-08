<?php

namespace App\Services\Exports;

use App\Services\Documents\PdfTable;
use App\Support\Exports\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rows in, a file out — Excel, CSV or PDF, the same for every export.
 *
 * Rows are read a thousand at a time and written as they arrive, so a
 * register of any length never sits in memory whole. Each format writes a
 * value the way its readers expect: money a number in Excel (so it adds up),
 * plain "1250.00" in a CSV (so other software parses it), "1,250.00" in a
 * PDF (so a person reads it). Excel and PDF close with a totals row under
 * the amounts; a CSV does not, because a row that is not a record breaks
 * every program that imports one.
 *
 * Two ways out, one writer: straight to the browser (download — the rows
 * ticked on screen) or to a file on disk, row by row with a tick after each
 * (toFile — the queued run, whose tick is the progress the screen shows).
 */
final class ExportWriter
{
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /**
     * The most rows a PDF carries — about seven hundred pages, past anything
     * a clinic prints. Excel and CSV have no limit.
     */
    public const PDF_LIMIT = 25000;

    private const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly PdfTable $pdf,
    ) {}

    /**
     * @param  list<Column>  $columns  what to write, in order
     * @param  callable(Model): array<string, mixed>  $read  one record as its cells, keyed by column
     * @param  array{organization: string, title: string, noun: string, by: string, filters?: list<string>, tab?: string, export?: string}  $heading
     * @param  (callable(Model): bool)|null  $muted  rows to print in grey in a PDF — an inactive patient
     */
    public function download(
        string $format,
        string $name,
        array $columns,
        Builder $query,
        callable $read,
        int $total,
        array $heading,
        ?callable $muted = null,
    ): Response|StreamedResponse|BinaryFileResponse {
        $file = $this->fileName($name, $format);

        return match ($format) {
            'csv' => response()->streamDownload(function () use ($columns, $query, $read) {
                $out = fopen('php://output', 'w');
                $this->writeCsv($out, $columns, $query, $read);
                fclose($out);
            }, $file, ['Content-Type' => self::MIME['csv']]),

            'pdf' => response($this->renderPdf($columns, $query, $read, $total, $heading, $muted), 200, [
                'Content-Type' => self::MIME['pdf'],
                'Content-Disposition' => 'attachment; filename="'.$file.'"',
            ]),

            default => response()
                ->download($this->buildXlsx($columns, $query, $read, $heading['title']), $file, ['Content-Type' => self::MIME['xlsx']])
                ->deleteFileAfterSend(),
        };
    }

    /**
     * The same file, written to a path on disk.
     *
     * @param  list<Column>  $columns
     * @param  callable(Model): array<string, mixed>  $read
     * @param  array{organization: string, title: string, noun: string, by: string, filters?: list<string>, tab?: string, export?: string}  $heading
     * @param  (callable(Model): bool)|null  $muted
     * @param  (callable(int): void)|null  $tick  after each row, with how many are written — it may throw to stop
     */
    public function toFile(
        string $format,
        string $path,
        array $columns,
        Builder $query,
        callable $read,
        int $total,
        array $heading,
        ?callable $muted = null,
        ?callable $tick = null,
    ): void {
        if ($format === 'csv') {
            $out = fopen($path, 'w');

            if ($out === false) {
                throw new RuntimeException('The export file could not be opened for writing.');
            }

            try {
                $this->writeCsv($out, $columns, $query, $read, $tick);
            } finally {
                fclose($out);
            }

            return;
        }

        if ($format === 'pdf') {
            file_put_contents($path, $this->renderPdf($columns, $query, $read, $total, $heading, $muted, $tick));

            return;
        }

        // Built in the temporary folder, then moved: copy, because the two may be different drives.
        $built = $this->buildXlsx($columns, $query, $read, $heading['title'], $tick);

        try {
            if (! copy($built, $path)) {
                throw new RuntimeException('The export file could not be saved.');
            }
        } finally {
            @unlink($built);
        }
    }

    /** "billing-invoices-2026-11-08.xlsx" */
    public function fileName(string $name, string $format): string
    {
        return $name.'-'.Carbon::today()->toDateString().'.'.$format;
    }

    public static function mime(string $format): string
    {
        return self::MIME[$format] ?? 'application/octet-stream';
    }

    /** A value as a person reads it — the PDF's cells, and the screen's preview. */
    public function display(Column $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            Column::MONEY => number_format((float) $value, 2),
            Column::NUMBER => is_float($value) ? number_format($value, 2) : number_format((int) $value),
            default => (string) $value,
        };
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function displayRow(array $columns, array $row): array
    {
        return array_map(fn (Column $column) => $this->display($column, $row[$column->key] ?? null), $columns);
    }

    /* ------------------------------------------------------------- formats */

    /**
     * @param  resource  $out
     * @param  list<Column>  $columns
     */
    private function writeCsv($out, array $columns, Builder $query, callable $read, ?callable $tick = null): void
    {
        // So Excel reads ₹ and names in any script as UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (Column $column) => $column->label, $columns));

        $done = 0;

        $query->chunk(1000, function ($records) use ($out, $columns, $read, $tick, &$done) {
            foreach ($records as $record) {
                $row = $read($record);

                fputcsv($out, array_map(function (Column $column) use ($row) {
                    $value = $row[$column->key] ?? null;

                    return $column->type === Column::MONEY && $value !== null
                        ? number_format((float) $value, 2, '.', '')
                        : ($value ?? '');
                }, $columns));

                $done++;

                if ($tick !== null) {
                    $tick($done);
                }
            }
        });
    }

    /**
     * @param  list<Column>  $columns
     * @return string the path of the finished workbook, in the temporary folder
     */
    private function buildXlsx(array $columns, Builder $query, callable $read, string $title, ?callable $tick = null): string
    {
        $sheet = (new XlsxWriter($title))->header(array_map(fn (Column $column) => $column->label, $columns));
        $sums = $this->emptySums($columns);
        $count = 0;

        $query->chunk(1000, function ($records) use ($sheet, $columns, $read, $tick, &$sums, &$count) {
            foreach ($records as $record) {
                $row = $read($record);
                $this->addUp($sums, $columns, $row);
                $count++;

                $sheet->row(array_map(fn (Column $column) => $row[$column->key] ?? null, $columns));

                if ($tick !== null) {
                    $tick($count);
                }
            }
        });

        if ($count > 0 && $sums !== []) {
            $sheet->footer($this->totalsRow($columns, $sums, fn (Column $column, float $sum) => round($sum, 2)));
        }

        return $sheet->save();
    }

    /**
     * @param  list<Column>  $columns
     * @return string the PDF's bytes
     */
    private function renderPdf(
        array $columns,
        Builder $query,
        callable $read,
        int $total,
        array $heading,
        ?callable $muted,
        ?callable $tick = null,
    ): string {
        /*
         * A long register is a long render. Raised for this render only, and
         * only ever raised: under the CLI there is no limit at all, and a 300
         * left behind kills whatever runs next in the same process — a queue
         * worker, or the test suite — five minutes after its first PDF export.
         */
        $limit = (int) ini_get('max_execution_time');
        $raised = $limit !== 0 && $limit < 300 && set_time_limit(300);

        $shown = min($total, self::PDF_LIMIT);
        $sums = $this->emptySums($columns);
        $done = 0;

        $notes = $shown < $total ? [[
            'text' => 'The first '.number_format($shown).' of '.number_format($total).' '.$heading['noun'].' are listed. '
                .'Download Excel or CSV for all of them, or narrow the dates.',
            'tone' => 'warning',
        ]] : [];

        $rows = $query->lazy(1000)->take($shown)->map(function (Model $record) use ($columns, $read, $muted, $tick, &$sums, &$done) {
            $row = $read($record);
            $this->addUp($sums, $columns, $row);
            $done++;

            if ($tick !== null) {
                $tick($done);
            }

            return ['cells' => $this->displayRow($columns, $row), 'muted' => $muted !== null && $muted($record)];
        });

        try {
            return $this->pdf->render(
                [
                    'organization' => $heading['organization'],
                    'title' => $heading['title'],
                    'eyebrow' => isset($heading['tab']) ? $heading['tab'].' export' : null,
                    'stats' => [
                        ['value' => number_format($total), 'label' => ucfirst($heading['noun'])],
                        ['value' => Carbon::now()->format('d M Y, h:i A'), 'label' => 'Generated by '.$heading['by']],
                    ],
                    'chips' => $heading['filters'] ?? [],
                    'notes' => $notes,
                    'confidential' => true,
                ],
                array_map(fn (Column $column) => [
                    'label' => $column->label,
                    'width' => $column->width,
                    'align' => $column->isNumeric() ? 'right' : 'left',
                    'bold' => $column === $columns[0],
                    // "20 Oct 2026" over three lines, or an amount split, reads wrong.
                    'nowrap' => $column->type !== Column::TEXT,
                ], $columns),
                $rows,
                empty: 'Nothing matches these filters.',
                // By reference: the sums fill in while the rows are drawn, after this is written.
                totals: function () use ($columns, &$sums) {
                    return $sums === [] ? null : $this->totalsRow(
                        $columns,
                        $sums,
                        fn (Column $column, float $sum) => number_format($sum, 2),
                    );
                },
            );
        } finally {
            if ($raised) {
                set_time_limit($limit);
            }
        }
    }

    /* -------------------------------------------------------------- totals */

    /**
     * A running sum for each money column; none at all when there is no money.
     *
     * @param  list<Column>  $columns
     * @return array<string, float>
     */
    private function emptySums(array $columns): array
    {
        $sums = [];

        foreach ($columns as $column) {
            if ($column->type === Column::MONEY) {
                $sums[$column->key] = 0.0;
            }
        }

        return $sums;
    }

    /**
     * @param  array<string, float>  $sums
     * @param  list<Column>  $columns
     * @param  array<string, mixed>  $row
     */
    private function addUp(array &$sums, array $columns, array $row): void
    {
        foreach ($sums as $key => $sum) {
            $sums[$key] = $sum + (float) ($row[$key] ?? 0);
        }
    }

    /**
     * "Total" under the first column that is not money, the sums under the
     * money, nothing elsewhere.
     *
     * @param  list<Column>  $columns
     * @param  array<string, float>  $sums
     * @param  callable(Column, float): (string|float)  $write
     * @return list<string|float|null>
     */
    private function totalsRow(array $columns, array $sums, callable $write): array
    {
        $labelled = false;

        return array_map(function (Column $column) use ($sums, $write, &$labelled) {
            if (array_key_exists($column->key, $sums)) {
                return $write($column, $sums[$column->key]);
            }

            if (! $labelled) {
                $labelled = true;

                return 'Total';
            }

            return null;
        }, $columns);
    }
}
