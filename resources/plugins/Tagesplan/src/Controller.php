<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\Location;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Support\Collection;

/**
 * Day plan: one row per needed position, grouped by location, one column per time slot
 */
class Controller extends BaseController
{
    protected const COLORS = 9;

    // Badge colors of the shift types (categories)
    protected const CATEGORY_COLORS = ['#6f42c1', '#d63384', '#fd7e14', '#198754', '#0d6efd', '#795548'];

    // Shift type of most shifts, it is not marked
    protected ?int $mainShiftType = null;

    // Overlaps up to this length (in seconds) are shown as a handover in the same row
    protected const HANDOVER_MAX = 3600;

    protected array $permissions = [
        'tagesplan.view',
    ];

    public function __construct(
        protected Response $response,
        protected Shift $shift,
        protected Location $location
    ) {
    }

    public function index(Request $request): Response
    {
        $days = $this->shift->newQuery()
            ->toBase()
            ->selectRaw('DISTINCT DATE(`start`) AS `day`')
            ->orderBy('day')
            ->pluck('day')
            ->map(fn($day) => (string) $day)
            ->all();

        $day = $request->input('day');
        if (!in_array($day, $days, true)) {
            $day = $this->defaultDay($days);
        }

        $this->mainShiftType = $this->shift->newQuery()
            ->toBase()
            ->select('shift_type_id')
            ->groupBy('shift_type_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('shift_type_id');

        $columns = [];
        $groups = [];
        $categories = [];
        if ($day) {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00');
            $shifts = $this->shift->newQuery()
                ->with(['location', 'schedule', 'shiftType'])
                ->where('start', '>=', $start)
                ->where('start', '<', $start->copy()->addDay())
                ->orderBy('start')
                ->get();

            $columns = $this->columns($shifts);
            $groups = $this->groups($shifts, $columns);
            $categories = $shifts
                ->map(fn(Shift $shift) => $this->category($shift))
                ->filter()
                ->unique('name')
                ->sortBy('name')
                ->values()
                ->all();
        }

        $index = $day ? array_search($day, $days, true) : false;

        $locale = (string) session()->get('locale', 'de_DE');
        $dayLabels = [];
        foreach ($days as $value) {
            $dayLabels[$value] = Carbon::createFromFormat('Y-m-d', $value)
                ->locale($locale)
                ->isoFormat('dd, DD.MM.YYYY');
        }

        return $this->response->withView('tagesplan/index', [
            'days' => $dayLabels,
            'day' => $day,
            'previous_day' => $index !== false ? ($days[$index - 1] ?? null) : null,
            'next_day' => $index !== false ? ($days[$index + 1] ?? null) : null,
            'columns' => $columns,
            'groups' => $groups,
            'categories' => $categories,
            // Shift popup to re-open after signing up / off
            'open_shift' => (int) $request->input('shift') ?: null,
            'open_type' => (int) $request->input('type') ?: null,
        ]);
    }

    /**
     * Today if it has shifts, else the next day with shifts, else the last one
     *
     * @param string[] $days
     */
    protected function defaultDay(array $days): ?string
    {
        $today = Carbon::now()->format('Y-m-d');
        foreach ($days as $day) {
            if ($day >= $today) {
                return $day;
            }
        }

        return $days ? end($days) : null;
    }

    /**
     * Time slots between all shift start/end times, skipping gaps without any shift (e.g. breaks)
     *
     * @param Collection|Shift[] $shifts
     * @return array[] ['start' => timestamp, 'end' => timestamp, 'label' => string]
     */
    protected function columns(Collection $shifts): array
    {
        $bounds = $shifts
            ->flatMap(fn(Shift $shift) => [$shift->start->timestamp, $shift->end->timestamp])
            ->unique()
            ->sort()
            ->values();

        $timezone = Carbon::now()->timezone;
        $columns = [];
        for ($i = 0; $i < $bounds->count() - 1; $i++) {
            $start = $bounds[$i];
            $end = $bounds[$i + 1];
            $covered = $shifts->contains(
                fn(Shift $shift) => $shift->start->timestamp <= $start && $shift->end->timestamp >= $end
            );
            if ($covered) {
                $columns[] = [
                    'start' => $start,
                    'end' => $end,
                    'label' => Carbon::createFromTimestamp($start, $timezone)->format('H:i')
                        . ' – ' . Carbon::createFromTimestamp($end, $timezone)->format('H:i'),
                ];
            }
        }

        return $columns;
    }

    /**
     * @param Collection|Shift[] $shifts
     * @param array[] $columns Handover columns get marked
     */
    protected function groups(Collection $shifts, array &$columns): array
    {
        $needs = [];
        foreach ($shifts as $shift) {
            $needs[$shift->id] = $this->needs($shift);
        }

        // Fixed position per location across all days, used for sorting and colors
        $positions = $this->location->newQuery()
            ->get()
            ->sortBy(fn(Location $location) => static::sortKey($location->name))
            ->pluck('id')
            ->flip();

        $groups = [];
        $byLocation = $shifts->groupBy('location_id')
            ->sortBy(fn(Collection $locationShifts) => $positions[$locationShifts->first()->location_id]);

        foreach ($byLocation as $locationShifts) {
            $location = $locationShifts->first()->location;

            $types = [];
            foreach ($locationShifts as $shift) {
                foreach ($needs[$shift->id] as $typeId => $need) {
                    $types[$typeId] = $need['name'];
                }
            }
            uasort($types, fn($a, $b) => strnatcasecmp(
                static::sortKey($a, $location->name),
                static::sortKey($b, $location->name)
            ));

            // The location is shown as header row above its angel types, if they are not named like it
            $header = count($types) > 1 || mb_strtolower(reset($types)) != mb_strtolower($location->name);

            $rows = [];
            foreach ($types as $typeId => $typeName) {
                // Lanes per angel type: shifts of other types at this location do not take any rows
                $lanes = $this->lanes($locationShifts->filter(fn(Shift $shift) => isset($needs[$shift->id][$typeId])));
                $this->markHandovers($lanes, $columns);

                $typeRows = [];
                foreach ($lanes as $lane) {
                    $slots = 0;
                    foreach ($lane as $shift) {
                        $need = $needs[$shift->id][$typeId];
                        $slots = max($slots, max($need['count'], count($need['entries'])));
                    }

                    for ($slot = 0; $slot < $slots; $slot++) {
                        $typeRows[] = $this->cells($lane, $typeId, $slot, $columns, $needs);
                    }
                }

                $label = $header ? $typeName : $location->name;
                foreach ($typeRows as $number => $cells) {
                    $rows[] = [
                        // Only number the positions if there is more than one
                        'label' => count($typeRows) > 1 ? $label . ' ' . ($number + 1) : $label,
                        'cells' => $cells,
                        'type_start' => $number == 0 && $rows,
                    ];
                }
            }

            if ($rows) {
                $groups[] = [
                    'location' => $location,
                    'header' => $header,
                    'color' => $positions[$location->id] % self::COLORS,
                    'rows' => $rows,
                ];
            }
        }

        return $groups;
    }

    /**
     * Sort by the configured order (one location or angel type name per line), then by name
     *
     * A name can be a location and an angel type (e.g. "Bistro"),
     * so angel types use the first line after their location
     */
    public static function sortKey(string $name, ?string $location = null): string
    {
        static $order = null;
        if ($order === null) {
            $lines = preg_split('/\R/', (string) config('tagesplan_order', ''));
            $lines = array_values(array_filter(array_map(fn($line) => mb_strtolower(trim($line)), $lines)));
            $order = [];
            foreach ($lines as $position => $line) {
                $order[$line][] = $position;
            }
        }

        $name = mb_strtolower(trim($name));
        $positions = $order[$name] ?? [];
        $after = $location !== null ? (int) (($order[mb_strtolower(trim($location))] ?? [-1])[0]) : -1;
        $position = current(array_filter($positions, fn($position) => $position > $after))
            ?: ($positions[0] ?? 9999);

        return sprintf('%04d %s', $position, $name);
    }

    /**
     * Needed angel types of a shift with their (not freeloaded) entries
     *
     * @return array[] [angel_type_id => ['name' => string, 'count' => int, 'entries' => ShiftEntry[]]]
     */
    protected function needs(Shift $shift): array
    {
        $needs = [];
        foreach (NeededAngelTypes_by_shift($shift) as $need) {
            $typeId = (int) $need['angel_type_id'];
            $entries = collect($need['shift_entries'])->sortBy('id')->values()->all();
            $needs[$typeId] = [
                'name' => $need['name'],
                'count' => max((int) $need['count'], $needs[$typeId]['count'] ?? 0),
                'entries' => $entries,
            ];
        }

        return $needs;
    }

    /**
     * Distributes overlapping shifts of a location into separate lanes,
     * a shift starting shortly before the previous one ends stays in its lane as handover
     *
     * @param Collection|Shift[] $shifts
     * @return Shift[][]
     */
    protected function lanes(Collection $shifts): array
    {
        $lanes = [];
        foreach ($shifts->sortBy('start') as $shift) {
            foreach ($lanes as $i => $lane) {
                $last = $lane[count($lane) - 1];
                $beforeLast = $lane[count($lane) - 2] ?? null;
                if (
                    $last->end <= $shift->start
                    || ($this->isHandover($last, $shift) && (!$beforeLast || $beforeLast->end <= $shift->start))
                ) {
                    $lanes[$i][] = $shift;
                    continue 2;
                }
            }
            $lanes[] = [$shift];
        }

        return $lanes;
    }

    /**
     * The next shift starts shortly before the previous one ends (not the same or a contained time)
     */
    protected function isHandover(Shift $previous, Shift $next): bool
    {
        return $previous->start < $next->start
            && $previous->end > $next->start
            && $previous->end < $next->end
            && $previous->end->timestamp - $next->start->timestamp <= self::HANDOVER_MAX;
    }

    /**
     * @param Shift[][] $lanes
     * @param array[] $columns
     */
    protected function markHandovers(array $lanes, array &$columns): void
    {
        foreach ($lanes as $lane) {
            for ($i = 1; $i < count($lane); $i++) {
                foreach ($columns as $c => $column) {
                    if (
                        $column['start'] >= $lane[$i]->start->timestamp
                        && $column['end'] <= $lane[$i - 1]->end->timestamp
                    ) {
                        $columns[$c]['handover'] = true;
                    }
                }
            }
        }
    }

    /**
     * @param Shift[] $lane
     */
    protected function cells(array $lane, int $typeId, int $slot, array $columns, array $needs): array
    {
        $cells = [];
        $count = count($columns);
        $column = 0;

        while ($column < $count) {
            $covering = $this->covering($lane, $columns[$column]);
            $span = 1;
            while ($column + $span < $count && $this->covering($lane, $columns[$column + $span]) == $covering) {
                $span++;
            }

            if (!$covering) {
                $cells[] = ['colspan' => $span, 'state' => 'none'];
            } elseif (count($covering) == 1) {
                $cells[] = ['colspan' => $span] + $this->slot($covering[0], $typeId, $slot, $needs);
            } else {
                // Handover: the previous shift ends (first part) while the next one starts (second part)
                $cells[] = [
                    'colspan' => $span,
                    'state' => 'handover',
                    'parts' => array_map(fn(Shift $shift) => $this->slot($shift, $typeId, $slot, $needs), $covering),
                ];
            }
            $column += $span;
        }

        return $cells;
    }

    /**
     * Shifts of a lane covering a column, ordered by start
     *
     * @param Shift[] $lane
     * @return Shift[]
     */
    protected function covering(array $lane, array $column): array
    {
        return array_values(array_filter(
            $lane,
            fn(Shift $shift) => $shift->start->timestamp <= $column['start']
                && $shift->end->timestamp >= $column['end']
        ));
    }

    protected function slot(Shift $shift, int $typeId, int $slot, array $needs): array
    {
        $need = $needs[$shift->id][$typeId] ?? null;
        /** @var ShiftEntry|null $entry */
        $entry = $need['entries'][$slot] ?? null;

        return [
            'state' => $entry ? 'filled' : ($need && $slot < $need['count'] ? 'open' : 'none'),
            'name' => $entry?->user->displayName,
            'shift_id' => $shift->id,
            'type_id' => $typeId,
            // Without JavaScript: reload the day plan with this shift's popup
            'url' => url('/tagesplan', [
                'day' => $shift->start->format('Y-m-d'),
                'shift' => $shift->id,
                'type' => $typeId,
            ]),
            'title' => ($shift->title ?: $shift->shiftType->name)
                . ' (' . $shift->start->format('H:i') . ' – ' . $shift->end->format('H:i') . ')',
            'category' => $this->category($shift),
        ];
    }

    /**
     * Category (shift type) to mark the shift with, null for the main shift type
     *
     * @return array|null ['name' => string, 'color' => string]
     */
    protected function category(Shift $shift): ?array
    {
        if ($shift->shift_type_id == $this->mainShiftType) {
            return null;
        }

        return [
            'name' => $shift->shiftType->name,
            'color' => self::CATEGORY_COLORS[$shift->shift_type_id % count(self::CATEGORY_COLORS)],
        ];
    }
}
