<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Day plans of all days as Excel file, one sheet per day, in the layout of the former "Einsatzplan"
 */
class ExportController extends Controller
{
    // Same colors as the day plan (.tp-color-*)
    protected const FILLS = ['D0CECE', 'B4C6E7', 'FBE5D6', 'FFD5FF', 'C5E0B4', 'E2D8D0', 'FFF2CC', 'DEEBF7', 'EDEDED'];

    // Not needed positions
    protected const FILL_NONE = '7F7F7F';

    // Rows of the column header and the first position
    protected const HEADER_ROW = 6;

    protected array $permissions = [
        'tagesplan.manage',
    ];

    /**
     * Only the usual check of tagesplan.manage (admins, shift coordinators), not the visibility for helpers
     */
    public function hasPermission(ServerRequestInterface $request, string $method): ?bool
    {
        return null;
    }

    public function excel(): Response
    {
        $this->loadMainShiftType();
        $locale = (string) session()->get('locale', 'de_DE');
        $event = (string) (config('name') ?: config('app_name'));
        $title = __('tagesplan.excel.title', [$event]);

        $xlsx = new Xlsx();
        $names = [];
        foreach ($this->days() as $day) {
            [, $columns, $groups] = $this->dayPlan($day);
            if (!$groups) {
                continue;
            }

            $date = Carbon::createFromFormat('Y-m-d', $day)->locale($locale);
            $name = Xlsx::sheetName($date->isoFormat('dd D'));
            if (in_array($name, $names, true)) {
                $name = Xlsx::sheetName($date->isoFormat('dd D.M.'));
            }
            $names[] = $name;

            $this->sheet($xlsx, $xlsx->addSheet($name), $title, $date->isoFormat('dddd D. MMMM YYYY'), $columns, $groups);
        }

        if (!$names) {
            $xlsx->addSheet(__('tagesplan.title'));
        }

        $filename = trim((string) preg_replace('/[^\w\- ]+/u', '', $title)) . '.xlsx';

        return $this->response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . Str::ascii($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
            )
            ->withContent($xlsx->output());
    }

    /**
     * Title, date, time slots as column header, one row per position with the names in the covered time slots;
     * the positions of an angel type are framed and colored like the location in the day plan
     */
    protected function sheet(Xlsx $xlsx, int $sheet, string $title, string $date, array $columns, array $groups): void
    {
        $last = count($columns) + 1;

        $xlsx->cell($sheet, 1, 1, $title, ['bold' => true, 'size' => 18, 'align' => 'center']);
        $xlsx->merge($sheet, 1, 1, 1, $last);
        $xlsx->rowHeight($sheet, 1, 23.25);
        $xlsx->cell($sheet, 3, 1, $date, ['size' => 12, 'align' => 'center']);
        $xlsx->merge($sheet, 3, 1, 3, $last);

        $xlsx->columnWidth($sheet, 1, 26.7);
        foreach ($columns as $c => $column) {
            $label = $this->time($column['start']) . ' - ' . $this->time($column['end']);
            if ($column['handover'] ?? false) {
                $label = __('tagesplan.handover') . ' ' . $label;
            }
            $xlsx->columnWidth($sheet, $c + 2, 20.7);
            $xlsx->cell($sheet, self::HEADER_ROW, $c + 2, $label, [
                'bold' => true,
                'align' => 'center',
                'border' => [
                    'left' => $c == 0 ? 'thick' : 'thin',
                    'right' => $c + 2 == $last ? 'thick' : 'thin',
                    'top' => 'thick',
                    'bottom' => 'thick',
                ],
            ]);
        }
        $xlsx->freeze($sheet, self::HEADER_ROW + 1, 2);
        $xlsx->printTitles($sheet, self::HEADER_ROW);

        $row = self::HEADER_ROW + 1;
        foreach ($groups as $group) {
            $fill = self::FILLS[$group['color'] % count(self::FILLS)];

            // Blocks of one angel type
            $blocks = [];
            foreach ($group['rows'] as $position) {
                if (!$blocks || $position['type_start']) {
                    $blocks[] = [];
                }
                $blocks[count($blocks) - 1][] = $position;
            }

            foreach ($blocks as $block) {
                foreach ($block as $i => $position) {
                    $top = $i == 0 ? 'thick' : 'thin';
                    $bottom = $i == count($block) - 1 ? 'thick' : 'thin';

                    $xlsx->cell($sheet, $row, 1, $position['label'], [
                        'bold' => true,
                        'fill' => $fill,
                        'border' => ['left' => 'thick', 'right' => 'thick', 'top' => $top, 'bottom' => $bottom],
                    ]);

                    $column = 2;
                    foreach ($position['cells'] as $cell) {
                        [$value, $needed] = $this->cellValue($cell);
                        for ($span = 0; $span < $cell['colspan']; $span++, $column++) {
                            $xlsx->cell($sheet, $row, $column, $value, [
                                'fill' => $needed ? $fill : self::FILL_NONE,
                                'border' => [
                                    'left' => 'thin',
                                    'right' => $column == $last ? 'thick' : 'thin',
                                    'top' => $top,
                                    'bottom' => $bottom,
                                ],
                            ]);
                        }
                    }
                    $row++;
                }
            }
        }
    }

    /**
     * @return array [?string $value, bool $needed]
     */
    protected function cellValue(array $cell): array
    {
        if ($cell['state'] != 'handover') {
            return [$cell['name'] ?? null, $cell['state'] != 'none'];
        }

        // Handover: leaving / arriving person
        $parts = array_filter($cell['parts'], fn($part) => $part['state'] != 'none');
        $names = array_filter(array_map(fn($part) => $part['name'], $cell['parts']));

        return [$names ? implode(' / ', $names) : null, (bool) $parts];
    }

    protected function time(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, Carbon::now()->timezone)->format('G:i');
    }
}
