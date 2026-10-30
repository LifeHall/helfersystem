<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Location;
use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftType;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;

/**
 * Reads the import template: one row per needed angel type,
 * rows with the same date, time, location, shift type and title become one shift
 */
class Import
{
    // Column key => header in the template
    public const COLUMNS = [
        'date' => 'Datum',
        'start' => 'Von',
        'end' => 'Bis',
        'location' => 'Ort',
        'angel_type' => 'Helfertyp',
        'count' => 'Anzahl',
        'shift_type' => 'Schichttyp',
        'title' => 'Titel',
        'description' => 'Beschreibung',
    ];

    protected const REQUIRED = ['date', 'start', 'end', 'location', 'angel_type', 'count'];

    // Name of the sheet with the shifts in the template
    public const SHEET = 'Schichten';

    /** @var string[] */
    public array $errors = [];

    /** @var string[] */
    public array $warnings = [];

    /**
     * Rows of an uploaded Excel (xlsx) or CSV file
     *
     * @return array[] Row number => [column number => value]
     */
    public static function rowsFromFile(string $content, string $filename): array
    {
        if (str_starts_with($content, "PK\x03\x04")) {
            return (new XlsxReader($content))->rows(self::SHEET);
        }

        if (!str_ends_with(mb_strtolower($filename), '.csv') && !str_ends_with(mb_strtolower($filename), '.txt')) {
            throw new RuntimeException('Unknown file type');
        }

        // Excel saves CSV files as UTF-8 (with BOM) or Windows-1252
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $first = $lines[0] ?? '';
        $separator = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        if (substr_count($first, "\t") > substr_count($first, $separator)) {
            $separator = "\t";
        }

        $rows = [];
        foreach ($lines as $number => $line) {
            $cells = array_map(fn($value) => trim((string) $value), str_getcsv($line, $separator, '"', ''));
            $cells = array_filter($cells, fn($value) => $value !== '');
            if ($cells) {
                // Columns start at 1 like in Excel
                $rows[$number + 1] = array_combine(array_map(fn($key) => $key + 1, array_keys($cells)), $cells);
            }
        }

        return $rows;
    }

    /**
     * @param array[] $rows Row number => [column number => value]
     * @return array[] Shifts: ['lines' => int[], 'start' => 'Y-m-d H:i', 'end' => 'Y-m-d H:i', 'location' => string,
     *                 'shift_type' => string, 'title' => string, 'description' => string, 'needs' => [name => count]]
     */
    public function parse(array $rows, string $defaultShiftType): array
    {
        $columns = $this->columns($rows);
        if (!$columns) {
            return [];
        }

        $shifts = [];
        foreach ($rows as $line => $cells) {
            if ($line <= $columns['header']) {
                continue;
            }

            $value = fn(string $key) => isset($columns[$key]) ? ($cells[$columns[$key]] ?? '') : '';
            if (array_filter(array_map($value, self::REQUIRED), fn($cell) => $cell !== '') === []) {
                continue;
            }

            $missing = array_filter(self::REQUIRED, fn($key) => $value($key) === '');
            if ($missing) {
                $this->errors[] = __('schichtimport.error.missing', [
                    $line,
                    implode(', ', array_map(fn($key) => self::COLUMNS[$key], $missing)),
                ]);
                continue;
            }

            $date = $this->date($value('date'));
            $start = $this->time($value('start'));
            $end = $this->time($value('end'));
            $count = $value('count');
            $valid = true;
            foreach (['date' => $date, 'start' => $start, 'end' => $end] as $key => $parsed) {
                if ($parsed === null) {
                    $this->errors[] = __('schichtimport.error.value', [$line, self::COLUMNS[$key], $this->show($value($key))]);
                    $valid = false;
                }
            }
            if (!is_numeric($count) || (float) $count < 0 || floor((float) $count) != (float) $count) {
                $this->errors[] = __('schichtimport.error.value', [$line, self::COLUMNS['count'], $this->show($count)]);
                $valid = false;
            }
            if (!$valid) {
                continue;
            }

            $startTime = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $start);
            $endTime = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $end);
            // Shifts until midnight or later end on the next day
            if ($endTime <= $startTime) {
                $endTime->addDay();
            }

            $location = (string) $value('location');
            $shiftType = (string) $value('shift_type') ?: $defaultShiftType;
            $title = (string) $value('title');
            $key = mb_strtolower(implode('|', [$startTime->format('Y-m-d H:i'), $endTime->format('Y-m-d H:i'), $location, $shiftType, $title]));

            $shifts[$key] ??= [
                'lines' => [],
                'start' => $startTime->format('Y-m-d H:i'),
                'end' => $endTime->format('Y-m-d H:i'),
                'location' => $location,
                'shift_type' => $shiftType,
                'title' => $title,
                'description' => '',
                'needs' => [],
            ];
            $shift = &$shifts[$key];
            $shift['lines'][] = $line;
            $shift['description'] = $shift['description'] ?: (string) $value('description');

            $angelType = (string) $value('angel_type');
            $typeKey = mb_strtolower($angelType);
            $existing = array_filter(array_keys($shift['needs']), fn($name) => mb_strtolower($name) == $typeKey);
            if ($existing) {
                $this->errors[] = __('schichtimport.error.duplicate', [$line, $angelType]);
            } elseif ((int) $count == 0) {
                $this->warnings[] = __('schichtimport.warning.zero', [$line, $angelType]);
            } else {
                $shift['needs'][$angelType] = (int) $count;
            }
            unset($shift);
        }

        $shifts = array_filter($shifts, fn($shift) => $shift['needs']);
        usort($shifts, fn($a, $b) => [$a['start'], $a['location']] <=> [$b['start'], $b['location']]);

        return $shifts;
    }

    /**
     * Matches the names with the existing locations, angel types and shift types and finds existing shifts
     *
     * @return array ['shifts' => shifts with 'exists' => bool, 'new' => ['locations' => string[], …]]
     */
    public function resolve(array $shifts, bool $create): array
    {
        $lookups = $this->lookups();
        $new = ['locations' => [], 'angel_types' => [], 'shift_types' => []];

        foreach ($shifts as &$shift) {
            $lines = implode(', ', $shift['lines']);
            $names = [
                'locations' => [$shift['location']],
                'shift_types' => [$shift['shift_type']],
                'angel_types' => array_keys($shift['needs']),
            ];
            foreach ($names as $kind => $list) {
                foreach ($list as $name) {
                    if (!isset($lookups[$kind][mb_strtolower($name)])) {
                        $new[$kind][mb_strtolower($name)] = $name;
                        if (!$create) {
                            $this->errors[] = __('schichtimport.error.unknown.' . $kind, [$lines, $name]);
                        }
                    }
                }
            }

            $shift['exists'] = false;
            $location = $lookups['locations'][mb_strtolower($shift['location'])] ?? null;
            $shiftType = $lookups['shift_types'][mb_strtolower($shift['shift_type'])] ?? null;
            if ($location && $shiftType) {
                $shift['exists'] = Shift::query()
                    ->where('location_id', $location)
                    ->where('shift_type_id', $shiftType)
                    ->where('start', Carbon::createFromFormat('Y-m-d H:i', $shift['start']))
                    ->where('end', Carbon::createFromFormat('Y-m-d H:i', $shift['end']))
                    ->whereRaw('COALESCE(`title`, \'\') = ?', [$shift['title']])
                    ->exists();
                if ($shift['exists']) {
                    $this->warnings[] = __('schichtimport.warning.exists', [$lines]);
                }
            }
        }
        unset($shift);

        $this->errors = array_values(array_unique($this->errors));

        return [
            'shifts' => $shifts,
            'new' => array_map(fn($list) => array_values($list), $new),
        ];
    }

    /**
     * Creates the missing locations, angel types and shift types and the new shifts
     *
     * @return int Number of created shifts
     */
    public function execute(array $shifts, bool $create, string $transactionId, int $userId): int
    {
        $resolved = $this->resolve($shifts, $create);
        if ($this->errors) {
            throw new RuntimeException(implode("\n", $this->errors));
        }

        return Capsule::connection()->transaction(function () use ($resolved, $transactionId, $userId): int {
            foreach ($resolved['new']['locations'] as $name) {
                Location::create(['name' => $name]);
            }
            foreach ($resolved['new']['shift_types'] as $name) {
                ShiftType::create(['name' => $name, 'description' => '']);
            }
            foreach ($resolved['new']['angel_types'] as $name) {
                // New angel types are open to everybody, a confirmation can be required later
                $angelType = new AngelType(['name' => $name]);
                $angelType->restricted = false;
                $angelType->save();
            }

            $lookups = $this->lookups();
            $created = 0;
            foreach ($resolved['shifts'] as $shift) {
                if ($shift['exists']) {
                    continue;
                }

                $model = Shift::create([
                    'title' => $shift['title'],
                    'description' => $shift['description'],
                    'url' => '',
                    'start' => Carbon::createFromFormat('Y-m-d H:i', $shift['start']),
                    'end' => Carbon::createFromFormat('Y-m-d H:i', $shift['end']),
                    'shift_type_id' => $lookups['shift_types'][mb_strtolower($shift['shift_type'])],
                    'location_id' => $lookups['locations'][mb_strtolower($shift['location'])],
                    'created_by' => $userId,
                    'transaction_id' => $transactionId,
                ]);
                foreach ($shift['needs'] as $name => $count) {
                    NeededAngelType::create([
                        'shift_id' => $model->id,
                        'angel_type_id' => $lookups['angel_types'][mb_strtolower($name)],
                        'count' => $count,
                    ]);
                }
                $created++;
            }

            return $created;
        });
    }

    /**
     * @return array[] ['locations' => [lowercase name => id], 'angel_types' => …, 'shift_types' => …]
     */
    protected function lookups(): array
    {
        $map = fn($models) => $models->mapWithKeys(fn($model) => [mb_strtolower(trim($model->name)) => $model->id])->all();

        return [
            'locations' => $map(Location::all()),
            'angel_types' => $map(AngelType::all()),
            'shift_types' => $map(ShiftType::all()),
        ];
    }

    /**
     * Finds the header row and the column of each field
     *
     * @return array|null [key => column number, 'header' => row number]
     */
    protected function columns(array $rows): ?array
    {
        $names = array_map('mb_strtolower', self::COLUMNS);
        foreach (array_slice($rows, 0, 10, true) as $line => $cells) {
            $columns = [];
            foreach ($cells as $column => $value) {
                $key = array_search(mb_strtolower(trim((string) $value)), $names, true);
                if ($key !== false) {
                    $columns[$key] = $column;
                }
            }

            if (isset($columns['date'])) {
                $missing = array_diff(self::REQUIRED, array_keys($columns));
                if ($missing) {
                    $this->errors[] = __('schichtimport.error.columns', [
                        implode(', ', array_map(fn($key) => self::COLUMNS[$key], $missing)),
                    ]);
                    return null;
                }

                return $columns + ['header' => $line];
            }
        }

        $this->errors[] = __('schichtimport.error.header', [implode(', ', self::COLUMNS)]);

        return null;
    }

    /**
     * "24.11.2027", "24.11.27", "2027-11-24" or an Excel date
     */
    protected function date(string|float $value): ?string
    {
        if (is_float($value)) {
            return $value >= 1
                ? Carbon::create(1899, 12, 30)->addDays((int) floor($value))->format('Y-m-d')
                : null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $value, $matches)) {
            [, $day, $month, $year] = $matches;
            $year = strlen($year) == 2 ? '20' . $year : $year;
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
            [, $year, $month, $day] = $matches;
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    /**
     * "8:30", "08.30", "8" or an Excel time (fraction of a day), "24:00" is midnight
     */
    protected function time(string|float $value): ?string
    {
        if (is_float($value)) {
            // Date and time values: only the fraction of the day counts, 1.0 (24:00) is midnight
            $minutes = (int) round(($value - floor($value)) * 24 * 60);
            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(?:[:.]\d{2})?$/', $value, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) ($matches[2] ?? 0);
        if ($hours > 24 || $minutes > 59 || ($hours == 24 && $minutes > 0)) {
            return null;
        }

        return sprintf('%02d:%02d', $hours % 24, $minutes);
    }

    protected function show(string|float $value): string
    {
        return is_float($value) ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') : $value;
    }
}
