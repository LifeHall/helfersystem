<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Day plans of all days as Excel file (one sheet per day) or PDF (one page per day),
 * in the layout of the former "Einsatzplan"
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
        $title = $this->title();
        $xlsx = new Xlsx();
        $names = [];
        foreach ($this->plans() as $plan) {
            $name = Xlsx::sheetName($plan['date']->isoFormat('dd D'));
            if (in_array($name, $names, true)) {
                $name = Xlsx::sheetName($plan['date']->isoFormat('dd D.M.'));
            }
            $names[] = $name;

            $this->sheet($xlsx, $xlsx->addSheet($name), $title, $plan['label'], $plan['columns'], $plan['groups']);
        }

        if (!$names) {
            $xlsx->addSheet(__('tagesplan.title'));
        }

        return $this->download(
            $xlsx->output(),
            $title . '.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Same layout as the Excel file, one page per day
     */
    public function pdf(): Response
    {
        $title = $this->title();
        $pdf = new Pdf($title);
        foreach ($this->plans() as $plan) {
            $this->page($pdf, $title, $plan['label'], $plan['columns'], $plan['groups']);
        }

        if (!$pdf->pageCount()) {
            $pdf->addPage();
            $pdf->text(Pdf::WIDTH / 2, 40, $title, 16, true, 'center');
            $pdf->text(Pdf::WIDTH / 2, 70, __('tagesplan.no_shifts'), 11, false, 'center');
        }

        return $this->download($pdf->output(), $title . '.pdf', 'application/pdf');
    }

    protected function title(): string
    {
        return __('tagesplan.excel.title', [(string) (config('name') ?: config('app_name'))]);
    }

    /**
     * Days with shifts
     *
     * @return array[] [['date' => Carbon, 'label' => string, 'columns' => array[], 'groups' => array[]]]
     */
    protected function plans(): array
    {
        $this->loadMainShiftType();
        $locale = (string) session()->get('locale', 'de_DE');

        $plans = [];
        foreach ($this->days() as $day) {
            [, $columns, $groups] = $this->dayPlan($day);
            if (!$groups) {
                continue;
            }

            $date = Carbon::createFromFormat('Y-m-d', $day)->locale($locale);
            $plans[] = [
                'date' => $date,
                'label' => $date->isoFormat('dddd D. MMMM YYYY'),
                'columns' => $columns,
                'groups' => $groups,
            ];
        }

        return $plans;
    }

    protected function download(string $content, string $filename, string $type): Response
    {
        $filename = trim((string) preg_replace('/[^\w\- .]+/u', '', $filename));

        return $this->response
            ->withHeader('Content-Type', $type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . Str::ascii($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
            )
            ->withContent($content);
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
            $label = trim(implode(' ', $this->columnLabel($column)));
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
            foreach ($this->blocks($group) as $block) {
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
     * One page: title, date, time slots as column header, one row per position, scaled to fit the page;
     * cells of the same shift are joined
     */
    protected function page(Pdf $pdf, string $title, string $date, array $columns, array $groups): void
    {
        $margin = 28;
        $top = 76;
        $headerHeight = 24;
        $pdf->addPage();
        $pdf->text(Pdf::WIDTH / 2, 38, $title, 16, true, 'center');
        $pdf->text(Pdf::WIDTH / 2, 56, $date, 11, false, 'center');
        $pdf->text(
            Pdf::WIDTH - $margin,
            Pdf::HEIGHT - 12,
            __('tagesplan.pdf.date', [Carbon::now()->format('d.m.Y H:i')]),
            7,
            false,
            'right',
            .4
        );

        $blocks = [];
        foreach ($groups as $group) {
            foreach ($this->blocks($group) as $block) {
                $blocks[] = [self::FILLS[$group['color'] % count(self::FILLS)], $block];
            }
        }
        $rowCount = array_sum(array_map(fn($block) => count($block[1]), $blocks));
        $rowHeight = min(16, (Pdf::HEIGHT - 24 - $top - $headerHeight) / max(1, $rowCount));
        $size = max(4, min(9, $rowHeight * .62));

        $labelWidth = 60;
        foreach ($blocks as [, $block]) {
            foreach ($block as $position) {
                $labelWidth = max($labelWidth, $pdf->width($position['label'], $size, true) + 8);
            }
        }
        $width = Pdf::WIDTH - 2 * $margin;
        $labelWidth = min($labelWidth, $width * .25);
        $columnWidth = ($width - $labelWidth) / max(1, count($columns));
        $left = $margin + $labelWidth;
        $right = $margin + $width;
        $bottom = $top + $headerHeight + $rowCount * $rowHeight;

        // Lines are drawn after all boxes, so no box covers them
        $lines = [];
        $headerSize = min(8, $size + 1);
        foreach ($columns as $c => $column) {
            $x = $left + $c * $columnWidth;
            $labels = array_values(array_filter($this->columnLabel($column)));
            foreach ($labels as $i => $label) {
                $y = $top + $headerHeight / 2 + ($i - (count($labels) - 1) / 2) * ($headerSize + 1) + $headerSize * .35;
                $text = $pdf->fit($label, $columnWidth - 4, $headerSize, true);
                $pdf->text($x + $columnWidth / 2, $y, $text, $headerSize, true, 'center');
            }
            $lines[] = [$x, $top, $x, $top + $headerHeight, .5];
        }
        $lines[] = [$left, $top, $right, $top, 1.5];

        $y = $top + $headerHeight;
        foreach ($blocks as [$fill, $block]) {
            $lines[] = [$margin, $y, $right, $y, 1.5];
            foreach ($block as $position) {
                $textY = $y + $rowHeight / 2 + $size * .35;
                $pdf->box($margin, $y, $labelWidth, $rowHeight, $fill);
                $pdf->text($margin + 4, $textY, $pdf->fit($position['label'], $labelWidth - 8, $size, true), $size, true);

                $column = 0;
                foreach ($position['cells'] as $cell) {
                    [$value, $needed] = $this->cellValue($cell);
                    $x = $left + $column * $columnWidth;
                    $cellWidth = $cell['colspan'] * $columnWidth;
                    $pdf->box($x, $y, $cellWidth, $rowHeight, $needed ? $fill : self::FILL_NONE);
                    if ($value) {
                        $pdf->text($x + 3, $textY, $pdf->fit($value, $cellWidth - 6, $size), $size);
                    }
                    $lines[] = [$x, $y, $x, $y + $rowHeight, .5];
                    $column += $cell['colspan'];
                }

                $y += $rowHeight;
                $lines[] = [$margin, $y, $right, $y, .5];
            }
            $lines[] = [$margin, $y, $right, $y, 1.5];
        }

        $lines[] = [$margin, $top + $headerHeight, $margin, $bottom, 1.5];
        $lines[] = [$left, $top, $left, $bottom, 1.5];
        $lines[] = [$right, $top, $right, $bottom, 1.5];
        foreach ($lines as [$x1, $y1, $x2, $y2, $lineWidth]) {
            $pdf->line($x1, $y1, $x2, $y2, $lineWidth);
        }
    }

    /**
     * Rows of a location split into the blocks of one angel type
     *
     * @return array[][]
     */
    protected function blocks(array $group): array
    {
        $blocks = [];
        foreach ($group['rows'] as $position) {
            if (!$blocks || $position['type_start']) {
                $blocks[] = [];
            }
            $blocks[count($blocks) - 1][] = $position;
        }

        return $blocks;
    }

    /**
     * @return string[] [handover label or empty, time slot]
     */
    protected function columnLabel(array $column): array
    {
        return [
            ($column['handover'] ?? false) ? __('tagesplan.handover') : '',
            $this->time($column['start']) . ' - ' . $this->time($column['end']),
        ];
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
