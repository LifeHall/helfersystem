<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

/**
 * Minimal Excel writer (xlsx): text cells with font, fill, border and alignment,
 * column widths, merged cells, frozen panes and print settings
 *
 * The container has no zip extension, so the zip file is written here (deflate via zlib)
 */
class Xlsx
{
    /** @var array[] */
    protected array $sheets = [];

    /** @var string[] Style key => index of the cell format */
    protected array $styles = ['' => 0];

    /** @var string[] */
    protected array $fonts = ['<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'];

    /** @var string[] Fills 0 and 1 are reserved by Excel */
    protected array $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];

    /** @var string[] */
    protected array $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];

    /** @var string[] */
    protected array $cellFormats = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];

    /**
     * @return int Sheet index
     */
    public function addSheet(string $name): int
    {
        $this->sheets[] = [
            'name' => $name,
            'rows' => [],
            'heights' => [],
            'widths' => [],
            'merges' => [],
            'freeze' => null,
            'print_titles' => null,
        ];

        return count($this->sheets) - 1;
    }

    /**
     * @param array $style ['bold' => bool, 'size' => float, 'fill' => 'RRGGBB',
     *                     'border' => ['left' => 'thin'|'thick', 'right' => …, 'top' => …, 'bottom' => …],
     *                     'align' => 'center']
     */
    public function cell(int $sheet, int $row, int $column, ?string $value, array $style = []): void
    {
        $this->sheets[$sheet]['rows'][$row][$column] = [$value, $this->style($style)];
    }

    public function merge(int $sheet, int $row, int $column, int $toRow, int $toColumn): void
    {
        $this->sheets[$sheet]['merges'][] = static::reference($row, $column) . ':' . static::reference($toRow, $toColumn);
    }

    public function columnWidth(int $sheet, int $column, float $width): void
    {
        $this->sheets[$sheet]['widths'][$column] = $width;
    }

    public function rowHeight(int $sheet, int $row, float $height): void
    {
        $this->sheets[$sheet]['heights'][$row] = $height;
    }

    /**
     * Rows above and columns left of the cell stay visible when scrolling
     */
    public function freeze(int $sheet, int $row, int $column): void
    {
        $this->sheets[$sheet]['freeze'] = [$row, $column];
    }

    /**
     * Row repeated on every printed page
     */
    public function printTitles(int $sheet, int $row): void
    {
        $this->sheets[$sheet]['print_titles'] = $row;
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

    /**
     * Cell reference like "B7"
     */
    public static function reference(int $row, int $column): string
    {
        $letters = '';
        while ($column > 0) {
            $letters = chr(65 + ($column - 1) % 26) . $letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters . $row;
    }

    /**
     * Sheet names are max. 31 characters long and can not contain : \ / ? * [ ]
     */
    public static function sheetName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('~[:\\\\/?*\[\]]~', ' ', $name)), 0, 31);
    }

    protected function style(array $style): int
    {
        ksort($style);
        $key = $style ? json_encode($style) : '';
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }

        $font = '<font>' . (!empty($style['bold']) ? '<b/>' : '')
            . '<sz val="' . ($style['size'] ?? 11) . '"/><name val="Calibri"/><family val="2"/></font>';
        $fill = isset($style['fill'])
            ? '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $style['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>'
            : null;
        $border = '<border>';
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            $border .= isset($style['border'][$side])
                ? '<' . $side . ' style="' . $style['border'][$side] . '"><color auto="1"/></' . $side . '>'
                : '<' . $side . '/>';
        }
        $border .= '<diagonal/></border>';

        $fontId = $this->index($this->fonts, $font);
        $fillId = $fill ? $this->index($this->fills, $fill) : 0;
        $borderId = $this->index($this->borders, $border);

        $this->cellFormats[] = '<xf numFmtId="0" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ($fontId ? ' applyFont="1"' : '') . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
            . (isset($style['align'])
                ? ' applyAlignment="1"><alignment horizontal="' . $style['align'] . '" vertical="center"/></xf>'
                : '/>');

        return $this->styles[$key] = count($this->cellFormats) - 1;
    }

    protected function index(array &$list, string $xml): int
    {
        $index = array_search($xml, $list, true);
        if ($index === false) {
            $list[] = $xml;
            $index = count($list) - 1;
        }

        return $index;
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
        $names = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . $this->escape($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
            if ($sheet['print_titles']) {
                $row = $sheet['print_titles'];
                $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $index . '">'
                    . $this->escape("'" . str_replace("'", "''", $sheet['name']) . "'!\$" . $row . ':$' . $row)
                    . '</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . '<sheets>' . $sheets . '</sheets>'
            . ($names ? '<definedNames>' . $names . '</definedNames>' : '')
            . '</workbook>';
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
        $styles = count($this->sheets) + 1;

        return $xml . '<Relationship Id="rId' . $styles . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    protected function stylesheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->cellFormats) . '">' . implode('', $this->cellFormats) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    protected function worksheet(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';

        if ($sheet['freeze']) {
            [$row, $column] = $sheet['freeze'];
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane xSplit="' . ($column - 1) . '" ySplit="' . ($row - 1) . '" '
                . 'topLeftCell="' . static::reference($row, $column) . '" activePane="bottomRight" state="frozen"/>'
                . '<selection pane="bottomRight" activeCell="A1" sqref="A1"/>'
                . '</sheetView></sheetViews>';
        }

        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
        if ($sheet['widths']) {
            ksort($sheet['widths']);
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $column => $width) {
                $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        ksort($sheet['rows']);
        foreach ($sheet['rows'] as $row => $cells) {
            ksort($cells);
            $xml .= '<row r="' . $row . '"'
                . (isset($sheet['heights'][$row]) ? ' ht="' . $sheet['heights'][$row] . '" customHeight="1"' : '')
                . '>';
            foreach ($cells as $column => [$value, $style]) {
                $xml .= '<c r="' . static::reference($row, $column) . '"' . ($style ? ' s="' . $style . '"' : '');
                $xml .= $value === null || $value === ''
                    ? '/>'
                    : ' t="inlineStr"><is><t xml:space="preserve">' . $this->escape($value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($sheet['merges']) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $range) {
                $xml .= '<mergeCell ref="' . $range . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        return $xml
            . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="1"/>'
            . '</worksheet>';
    }

    protected function escape(string $value): string
    {
        // Control characters are not allowed in XML
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
            $header = pack(
                'vvvvvVVVv',
                20,
                0,
                8,
                $dosTime,
                $dosDate,
                crc32($content),
                strlen($compressed),
                strlen($content),
                strlen($name)
            );

            $directory .= pack('Vv', 0x02014b50, 20) . substr($header, 0, 22)
                . pack('vvvvvVV', strlen($name), 0, 0, 0, 0, 0, strlen($data))
                . $name;
            $data .= pack('V', 0x04034b50) . $header . pack('v', 0) . $name . $compressed;
        }

        return $data . $directory . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($files),
            count($files),
            strlen($directory),
            strlen($data),
            0
        );
    }
}
