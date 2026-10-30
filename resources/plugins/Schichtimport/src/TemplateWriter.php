<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport;

/**
 * Minimal Excel writer (xlsx) for the import template: text and number cells, a few fixed styles,
 * column widths and formats, frozen header row and drop-down lists (data validation)
 *
 * The container has no zip extension, so the zip file is written here (deflate via zlib)
 */
class TemplateWriter
{
    public const STYLE_HEADER = 1;
    public const STYLE_TEXT = 2;
    public const STYLE_TITLE = 3;
    public const STYLE_WRAP = 4;
    public const STYLE_EXAMPLE = 5;

    /** @var array[] */
    protected array $sheets = [];

    /**
     * @return int Sheet index
     */
    public function addSheet(string $name): int
    {
        $this->sheets[] = [
            'name' => $name,
            'rows' => [],
            'columns' => [],
            'freeze' => false,
            'validations' => [],
        ];

        return count($this->sheets) - 1;
    }

    public function cell(int $sheet, int $row, int $column, string|int|null $value, int $style = 0): void
    {
        $this->sheets[$sheet]['rows'][$row][$column] = [$value, $style];
    }

    /**
     * @param int $style Format of empty cells, e.g. STYLE_TEXT keeps typed dates as text
     */
    public function column(int $sheet, int $column, float $width, int $style = 0): void
    {
        $this->sheets[$sheet]['columns'][$column] = [$width, $style];
    }

    public function freezeHeader(int $sheet): void
    {
        $this->sheets[$sheet]['freeze'] = true;
    }

    /**
     * Drop-down with the values of a range like "Listen!$A$2:$A$50", other values only get a warning
     */
    public function listValidation(int $sheet, string $range, string $source): void
    {
        $this->sheets[$sheet]['validations'][] = '<dataValidation type="list" errorStyle="warning" allowBlank="1" '
            . 'showErrorMessage="1" sqref="' . $range . '"><formula1>' . $this->escape($source) . '</formula1></dataValidation>';
    }

    public function wholeNumberValidation(int $sheet, string $range): void
    {
        $this->sheets[$sheet]['validations'][] = '<dataValidation type="whole" operator="greaterThanOrEqual" '
            . 'allowBlank="1" showErrorMessage="1" sqref="' . $range . '"><formula1>0</formula1></dataValidation>';
    }

    public static function reference(int $row, int $column): string
    {
        $letters = '';
        while ($column > 0) {
            $letters = chr(65 + ($column - 1) % 26) . $letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters . $row;
    }

    public function output(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelations(),
            'xl/styles.xml' => $this->stylesheet(),
        ];
        foreach ($this->sheets as $index => $sheet) {
            $files['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $this->worksheet($sheet);
        }

        return $this->zip($files);
    }

    protected function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    protected function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . $this->escape($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    protected function workbookRelations(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Relationship Id="rId' . ($index + 1) . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }

        return $xml . '<Relationship Id="rId' . (count($this->sheets) + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Fixed styles: 0 normal, 1 header, 2 text format, 3 title, 4 wrapped text, 5 example (grey italic, text format)
     */
    protected function stylesheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><i/><sz val="11"/><color rgb="FF7F7F7F"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF44546A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="49" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    protected function worksheet(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if ($sheet['freeze']) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
                . '</sheetView></sheetViews>';
        }

        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
        if ($sheet['columns']) {
            ksort($sheet['columns']);
            $xml .= '<cols>';
            foreach ($sheet['columns'] as $column => [$width, $style]) {
                $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"'
                    . ($style ? ' style="' . $style . '"' : '') . '/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        ksort($sheet['rows']);
        foreach ($sheet['rows'] as $row => $cells) {
            ksort($cells);
            $xml .= '<row r="' . $row . '">';
            foreach ($cells as $column => [$value, $style]) {
                $xml .= '<c r="' . static::reference($row, $column) . '"' . ($style ? ' s="' . $style . '"' : '');
                if ($value === null || $value === '') {
                    $xml .= '/>';
                } elseif (is_int($value)) {
                    $xml .= '><v>' . $value . '</v></c>';
                } else {
                    $xml .= ' t="inlineStr"><is><t xml:space="preserve">' . $this->escape($value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($sheet['validations']) {
            $xml .= '<dataValidations count="' . count($sheet['validations']) . '">'
                . implode('', $sheet['validations']) . '</dataValidations>';
        }

        return $xml . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    }

    protected function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string[] $files Path => content
     */
    protected function zip(array $files): string
    {
        $data = '';
        $directory = '';
        $time = getdate();
        $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
        $dosDate = (($time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];

        foreach ($files as $name => $content) {
            $compressed = (string) gzdeflate($content, 6);
            $header = pack('vvvvvVVVv', 20, 0, 8, $dosTime, $dosDate, crc32($content), strlen($compressed), strlen($content), strlen($name));
            $directory .= pack('Vv', 0x02014b50, 20) . substr($header, 0, 22)
                . pack('vvvvvVV', strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
            $data .= pack('V', 0x04034b50) . $header . pack('v', 0) . $name . $compressed;
        }

        return $data . $directory
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($directory), strlen($data), 0);
    }
}
