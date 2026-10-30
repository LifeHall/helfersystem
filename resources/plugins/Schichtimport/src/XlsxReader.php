<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport;

use RuntimeException;
use SimpleXMLElement;

/**
 * Minimal Excel reader (xlsx): cell values of one sheet
 *
 * The container has no zip extension, so the zip file is read here (inflate via zlib)
 */
class XlsxReader
{
    protected const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    protected const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var string[] Path => content */
    protected array $files = [];

    public function __construct(string $content)
    {
        $this->files = $this->unzip($content);
        if (!isset($this->files['xl/workbook.xml'])) {
            throw new RuntimeException('No Excel file');
        }
    }

    /**
     * Rows of the sheet with this name (or the first sheet), cells as raw values:
     * numbers as float (dates and times are Excel serial numbers), everything else as string
     *
     * @return array[] Row number => [column number => value]
     */
    public function rows(?string $sheetName = null): array
    {
        $path = $this->sheetPath($sheetName);
        $strings = $this->sharedStrings();
        $sheet = $this->xml($path);

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                [$column, $number] = $this->position((string) $cell['r']);
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => $this->text($cell->is),
                    'str', 'e' => (string) $cell->v,
                    'b' => (string) $cell->v === '1' ? '1' : '0',
                    default => (string) $cell->v === '' ? '' : (float) (string) $cell->v,
                };
                if ($value !== '') {
                    $rows[$number][$column] = is_string($value) ? trim($value) : $value;
                }
            }
        }
        ksort($rows);

        return $rows;
    }

    protected function sheetPath(?string $sheetName): string
    {
        $workbook = $this->xml('xl/workbook.xml');
        $relations = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->Relationship as $relation) {
            $relations[(string) $relation['Id']] = ltrim(
                str_starts_with((string) $relation['Target'], '/')
                    ? (string) $relation['Target']
                    : 'xl/' . $relation['Target'],
                '/'
            );
        }

        $first = null;
        foreach ($workbook->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::NS_REL)['id'];
            $first ??= $relations[$id] ?? null;
            if ($sheetName !== null && mb_strtolower(trim((string) $sheet['name'])) == mb_strtolower($sheetName)) {
                return $relations[$id];
            }
        }

        if (!$first) {
            throw new RuntimeException('No sheet');
        }

        return $first;
    }

    /**
     * @return string[]
     */
    protected function sharedStrings(): array
    {
        if (!isset($this->files['xl/sharedStrings.xml'])) {
            return [];
        }

        $strings = [];
        foreach ($this->xml('xl/sharedStrings.xml')->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    /**
     * Text of a string item, also with formatted parts (rich text)
     */
    protected function text(SimpleXMLElement $item): string
    {
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * "C12" => [3, 12]
     */
    protected function position(string $reference): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches);
        $column = 0;
        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }

        return [$column, (int) $matches[2]];
    }

    protected function xml(string $path): SimpleXMLElement
    {
        if (!isset($this->files[$path])) {
            throw new RuntimeException('Missing ' . $path);
        }

        $xml = simplexml_load_string($this->files[$path], SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('Invalid ' . $path);
        }
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        return $xml;
    }

    /**
     * Reads the entries of a zip file using its central directory
     *
     * @return string[] Path => content
     */
    protected function unzip(string $zip): array
    {
        $end = strrpos($zip, pack('V', 0x06054b50));
        if ($end === false) {
            throw new RuntimeException('No zip file');
        }

        $directory = unpack('ventries/Vsize/Voffset', substr($zip, $end + 10, 10));
        $position = $directory['offset'];
        $files = [];
        for ($i = 0; $i < $directory['entries']; $i++) {
            $entry = unpack(
                'Vsignature/vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
                substr($zip, $position, 46)
            );
            if ($entry['signature'] != 0x02014b50) {
                throw new RuntimeException('Invalid zip directory');
            }
            $name = substr($zip, $position + 46, $entry['nameLength']);
            $position += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];

            // Only the XML parts are needed
            if (!str_ends_with($name, '.xml') && !str_ends_with($name, '.rels')) {
                continue;
            }

            $local = unpack('vnameLength/vextraLength', substr($zip, $entry['offset'] + 26, 4));
            $data = substr(
                $zip,
                $entry['offset'] + 30 + $local['nameLength'] + $local['extraLength'],
                $entry['compressed']
            );
            $content = match ($entry['method']) {
                0 => $data,
                8 => @gzinflate($data),
                default => false,
            };
            if ($content === false) {
                throw new RuntimeException('Unsupported zip entry ' . $name);
            }
            $files[$name] = $content;
        }

        return $files;
    }
}
