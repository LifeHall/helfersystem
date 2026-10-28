<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Helpers\Carbon;

/**
 * Own shifts of a helper as A4 list: one block per day with time, location and helper type
 */
class ShiftPdf
{
    protected const MARGIN = 50;
    protected const BOTTOM = 790;
    protected const ROW = 18;

    protected Pdf $pdf;

    protected float $y = 0;

    /** @var array[] [['label' => string, 'x' => float, 'width' => float, 'align' => string]] */
    protected array $columns = [];

    /**
     * @param array[] $days Shift days from HomeController::shiftDays()
     */
    public function render(string $name, string $appName, array $days): string
    {
        $this->pdf = new Pdf(__('startseite.pdf.title') . ' – ' . $name);
        $this->columns = $this->columns($days);

        $this->pdf->addPage();
        $this->header($name, $appName, $days);

        if (!$days) {
            $this->pdf->text(self::MARGIN, $this->y + 10, __('startseite.shifts.none'), 11);
        }

        foreach ($days as $day) {
            // Day header with at least one row on the same page
            $this->ensureSpace(self::ROW * 2 + 6);
            $this->dayHeader($day);
            foreach ($day['entries'] as $entry) {
                $this->ensureSpace(self::ROW);
                $this->row($entry);
            }
            $this->y += 10;
        }

        $this->footers($name, $appName);

        return $this->pdf->output();
    }

    protected function header(string $name, string $appName, array $days): void
    {
        $right = Pdf::WIDTH - self::MARGIN;
        $count = array_sum(array_map(fn($day) => count($day['entries']), $days));
        $hours = array_sum(array_map(fn($day) => $day['hours'], $days));

        if ($appName) {
            $this->pdf->text(self::MARGIN, 58, $appName, 10, false, 'left', .4);
        }
        $this->pdf->text(self::MARGIN, 84, __('startseite.pdf.title'), 22, true);
        $this->pdf->text(
            $right,
            84,
            __('startseite.pdf.date', [Carbon::now()->format('d.m.Y H:i')]),
            9,
            false,
            'right',
            .4
        );
        $this->pdf->text(self::MARGIN, 106, $name, 14);
        $this->pdf->text(
            self::MARGIN,
            124,
            __('startseite.shifts.summary', [$count, round($hours, 2)]),
            10,
            false,
            'left',
            .3
        );
        $this->pdf->line(self::MARGIN, 136, $right, 136, 1.5);

        $this->y = 150;
        $this->columnHeader();
    }

    protected function columnHeader(): void
    {
        foreach ($this->columns as $column) {
            $x = $column['align'] == 'right' ? $column['x'] + $column['width'] - 6 : $column['x'] + 6;
            $this->pdf->text($x, $this->y + 10, mb_strtoupper($column['label']), 8, true, $column['align'], .4);
        }
        $this->y += 16;
    }

    protected function dayHeader(array $day): void
    {
        $width = Pdf::WIDTH - 2 * self::MARGIN;
        $this->pdf->box(self::MARGIN, $this->y, $width, self::ROW + 2, .9);
        $this->pdf->text(self::MARGIN + 6, $this->y + 13.5, $day['label'], 11, true);
        $this->pdf->text(
            self::MARGIN + $width - 6,
            $this->y + 13.5,
            $this->hours($day['hours']),
            10,
            true,
            'right'
        );
        $this->y += self::ROW + 2;
    }

    protected function row(array $entry): void
    {
        $values = [
            'time' => $entry['time'],
            'location' => $entry['location'],
            'angel_type' => $entry['angel_type'],
            'title' => $entry['title'],
            'hours' => $this->hours($entry['hours']),
        ];

        foreach ($this->columns as $key => $column) {
            $text = $this->pdf->fit((string) $values[$key], $column['width'] - 6, 10);
            $x = $column['align'] == 'right' ? $column['x'] + $column['width'] - 6 : $column['x'] + 6;
            $this->pdf->text($x, $this->y + 12.5, $text, 10, $key == 'time', $column['align']);
        }

        $this->y += self::ROW;
        $this->pdf->line(self::MARGIN, $this->y, Pdf::WIDTH - self::MARGIN, $this->y, .5, .8);
    }

    protected function ensureSpace(float $height): void
    {
        if ($this->y + $height <= self::BOTTOM) {
            return;
        }

        $this->pdf->addPage();
        $this->y = 50;
        $this->columnHeader();
    }

    protected function footers(string $name, string $appName): void
    {
        $pages = $this->pdf->pageCount();
        for ($page = 0; $page < $pages; $page++) {
            $this->pdf->setPage($page);
            $this->pdf->line(self::MARGIN, 808, Pdf::WIDTH - self::MARGIN, 808, .5, .7);
            $this->pdf->text(
                self::MARGIN,
                820,
                trim($appName . ' · ' . $name, ' ·'),
                8,
                false,
                'left',
                .4
            );
            $this->pdf->text(
                Pdf::WIDTH - self::MARGIN,
                820,
                __('startseite.pdf.page', [$page + 1, $pages]),
                8,
                false,
                'right',
                .4
            );
        }
    }

    /**
     * Column layout, the shift name column only if any shift has a name
     */
    protected function columns(array $days): array
    {
        $hasTitle = false;
        foreach ($days as $day) {
            foreach ($day['entries'] as $entry) {
                $hasTitle = $hasTitle || $entry['title'];
            }
        }

        $widths = [
            'time' => 90,
            'location' => 125,
            'angel_type' => $hasTitle ? 150 : 235,
            'title' => $hasTitle ? 85 : 0,
            'hours' => 45,
        ];

        $columns = [];
        $x = self::MARGIN;
        foreach (array_filter($widths) as $key => $width) {
            $columns[$key] = [
                'label' => __('startseite.column.' . $key),
                'x' => $x,
                'width' => $width,
                'align' => $key == 'hours' ? 'right' : 'left',
            ];
            $x += $width;
        }

        return $columns;
    }

    protected function hours(float $hours): string
    {
        return round($hours, 2) . ' h';
    }
}
