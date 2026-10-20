<?php

namespace App\Support\Exports;

use RuntimeException;
use ZipArchive;

/**
 * One sheet of rows, written as a real .xlsx.
 *
 * An .xlsx is a zip of a handful of XML files, and a register needs none of
 * what a spreadsheet library is for — no formulas, no charts, no reading. So
 * this writes those few files itself rather than adding a dependency every
 * clinic's server would have to carry: a bold header row that stays put while
 * the sheet scrolls and carries the filter arrows, columns sized to what is in
 * them, and numbers stored as numbers so they add up.
 *
 * Text goes in as inline strings — no shared-string table to build — which is
 * slightly larger on disk and opens the same everywhere.
 */
class XlsxWriter
{
    /** @var list<string> */
    private array $header = [];

    /** @var list<string> each row's finished <row> element */
    private array $rows = [];

    /** @var list<string|int|float|null>|null a bold closing row — totals — kept out of the filter */
    private ?array $footer = null;

    /** @var list<int> the widest value seen in each column, in characters */
    private array $widths = [];

    public function __construct(private readonly string $sheetName = 'Sheet1') {}

    /** @param list<string> $titles */
    public function header(array $titles): self
    {
        $this->header = array_values($titles);
        $this->measure($this->header);

        return $this;
    }

    /** @param list<string|int|float|null> $values */
    public function row(array $values): self
    {
        $this->measure($values);
        $this->rows[] = $this->rowXml(count($this->rows) + 2, array_values($values), false);

        return $this;
    }

    /**
     * A last row in bold — the totals under a column of amounts. Numbers
     * stay numbers, and the row sits outside the filter range so sorting the
     * sheet never moves it into the middle.
     *
     * @param  list<string|int|float|null>  $values
     */
    public function footer(array $values): self
    {
        $this->measure($values);
        $this->footer = array_values($values);

        return $this;
    }

    /** Writes the workbook and returns its path; the caller sends and deletes it. */
    public function save(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write the spreadsheet.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        return $path;
    }

    /* ------------------------------------------------------------ the sheet */

    private function sheet(): string
    {
        $columns = max(count($this->header), 1);
        $last = $this->column($columns - 1);
        $lastRow = count($this->rows) + 1;

        $cols = '';
        foreach (range(0, $columns - 1) as $index) {
            // Wide enough for what is there, never a wall of one column.
            $width = min(max(($this->widths[$index] ?? 8) + 2, 8), 60);
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $index + 1, $width);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            // The header stays in view while the rows scroll under it.
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$this->rowXml(1, $this->header, true).implode('', $this->rows)
            .($this->footer !== null ? $this->rowXml($lastRow + 1, $this->footer, false, true) : '')
            .'</sheetData>'
            .sprintf('<autoFilter ref="A1:%s%d"/>', $last, $lastRow)
            .'</worksheet>';
    }

    /** @param list<string|int|float|null> $values */
    private function rowXml(int $number, array $values, bool $isHeader, bool $bold = false): string
    {
        $cells = '';

        foreach ($values as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $ref = $this->column($index).$number;
            $style = $isHeader || $bold ? ' s="1"' : '';

            if (! $isHeader && (is_int($value) || is_float($value))) {
                $cells .= sprintf('<c r="%s"%s><v>%s</v></c>', $ref, $style, $value);

                continue;
            }

            $cells .= sprintf(
                '<c r="%s" t="inlineStr"%s><is><t xml:space="preserve">%s</t></is></c>',
                $ref,
                $style,
                $this->escape((string) $value),
            );
        }

        return sprintf('<row r="%d">%s</row>', $number, $cells);
    }

    /** @param list<string|int|float|null> $values */
    private function measure(array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $this->widths[$index] = max($this->widths[$index] ?? 0, mb_strlen((string) $value));
        }
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        // Control characters other than tab and newlines are not allowed in XML at all.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /* ------------------------------------------------------- the packaging */

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        // Excel refuses a sheet name over 31 characters or with any of []:*?/\
        $name = mb_substr((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $this->sheetName), 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($name).'" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            .$this->escape("'{$name}'").'!$A$1:$'.$this->column(max(count($this->header), 1) - 1).'$'.(count($this->rows) + 1)
            .'</definedName></definedNames>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Style 0 is plain; style 1 is the header — bold, on a pale blue fill. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
