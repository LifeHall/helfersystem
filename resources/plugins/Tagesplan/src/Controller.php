<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Support\Collection;

/**
 * Day plan: one row per needed position, grouped by location, one column per time slot
 */
class Controller extends BaseController
{
    protected const COLORS = 8;

    protected array $permissions = [
        'user_shifts',
    ];

    public function __construct(
        protected Response $response,
        protected Shift $shift
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

        $columns = [];
        $groups = [];
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
     * Time slots between all shift start/end times, skipping slots without any shift
     *
     * @param Collection|Shift[] $shifts
     * @return array[] ['start' => timestamp, 'end' => timestamp]
     */
    protected function columns(Collection $shifts): array
    {
        $bounds = $shifts
            ->flatMap(fn(Shift $shift) => [$shift->start->timestamp, $shift->end->timestamp])
            ->unique()
            ->sort()
            ->values();

        $columns = [];
        for ($i = 0; $i < $bounds->count() - 1; $i++) {
            $start = $bounds[$i];
            $end = $bounds[$i + 1];
            $covered = $shifts->contains(
                fn(Shift $shift) => $shift->start->timestamp <= $start && $shift->end->timestamp >= $end
            );
            if ($covered) {
                $timezone = Carbon::now()->timezone;
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
     */
    protected function groups(Collection $shifts, array $columns): array
    {
        $needs = [];
        foreach ($shifts as $shift) {
            $needs[$shift->id] = $this->needs($shift);
        }

        $groups = [];
        $byLocation = $shifts->groupBy('location_id')
            ->sortBy(fn(Collection $locationShifts) => mb_strtolower($locationShifts->first()->location->name));

        foreach ($byLocation->values() as $index => $locationShifts) {
            $location = $locationShifts->first()->location;
            $lanes = $this->lanes($locationShifts);

            $types = [];
            foreach ($locationShifts as $shift) {
                foreach ($needs[$shift->id] as $typeId => $need) {
                    $types[$typeId] = $need['name'];
                }
            }
            natcasesort($types);

            $rows = [];
            foreach ($types as $typeId => $typeName) {
                $number = 0;
                foreach ($lanes as $lane) {
                    $slots = 0;
                    foreach ($lane as $shift) {
                        $need = $needs[$shift->id][$typeId] ?? null;
                        $slots = max($slots, $need ? max($need['count'], count($need['entries'])) : 0);
                    }

                    for ($slot = 0; $slot < $slots; $slot++) {
                        $number++;
                        $rows[] = [
                            'label' => count($types) > 1
                                ? $location->name . ' – ' . $typeName . ' ' . $number
                                : $location->name . ' ' . $number,
                            'cells' => $this->cells($lane, $typeId, $slot, $columns, $needs),
                        ];
                    }
                }
            }

            if ($rows) {
                $groups[] = [
                    'location' => $location,
                    'color' => $index % self::COLORS,
                    'rows' => $rows,
                ];
            }
        }

        return $groups;
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
     * Distributes overlapping shifts of a location into separate lanes
     *
     * @param Collection|Shift[] $shifts
     * @return Shift[][]
     */
    protected function lanes(Collection $shifts): array
    {
        $lanes = [];
        foreach ($shifts->sortBy('start') as $shift) {
            foreach ($lanes as $i => $lane) {
                if ($lane[count($lane) - 1]->end <= $shift->start) {
                    $lanes[$i][] = $shift;
                    continue 2;
                }
            }
            $lanes[] = [$shift];
        }

        return $lanes;
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
            $shift = null;
            foreach ($lane as $laneShift) {
                if (
                    $laneShift->start->timestamp <= $columns[$column]['start']
                    && $laneShift->end->timestamp >= $columns[$column]['end']
                ) {
                    $shift = $laneShift;
                    break;
                }
            }

            if (!$shift) {
                $cells[] = ['colspan' => 1, 'state' => 'none'];
                $column++;
                continue;
            }

            $span = 0;
            while ($column + $span < $count && $columns[$column + $span]['end'] <= $shift->end->timestamp) {
                $span++;
            }

            $need = $needs[$shift->id][$typeId] ?? null;
            /** @var ShiftEntry|null $entry */
            $entry = $need['entries'][$slot] ?? null;

            $cells[] = [
                'colspan' => $span,
                'state' => $entry ? 'filled' : ($need && $slot < $need['count'] ? 'open' : 'none'),
                'name' => $entry?->user->displayName,
                'url' => url('/shifts', ['action' => 'view', 'shift_id' => $shift->id]),
                'title' => $shift->title ?: $shift->shiftType->name,
            ];
            $column += $span;
        }

        return $cells;
    }
}
