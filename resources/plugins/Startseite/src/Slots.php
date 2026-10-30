<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Support\Collection;

/**
 * Time slots (needed helper type of a shift) for the guided sign up and the automatic assignment
 */
class Slots
{
    /**
     * Needed helper types of all shifts that have not ended yet
     *
     * Same sources as NeededAngelTypes_by_shift(), but loaded for all shifts at once
     *
     * @param int[]|null $typeIds Only these helper types
     * @return Collection|array[] [['key', 'shift', 'type', 'day', 'from', 'to', 'count', 'taken']]
     */
    public static function load(?array $typeIds = null): Collection
    {
        $shifts = Shift::with(['location', 'shiftType', 'schedule'])
            ->where('end', '>', Carbon::now())
            ->orderBy('start')
            ->get();
        if ($shifts->isEmpty()) {
            return new Collection();
        }

        $byShift = NeededAngelType::whereIn('shift_id', $shifts->pluck('id'))->get()->groupBy('shift_id');
        $byShiftType = NeededAngelType::whereNotNull('shift_type_id')->get()->groupBy('shift_type_id');
        $byLocation = NeededAngelType::whereNotNull('location_id')->get()->groupBy('location_id');
        $taken = ShiftEntry::whereIn('shift_id', $shifts->pluck('id'))
            ->whereNull('freeloaded_by')
            ->get()
            ->countBy(fn(ShiftEntry $entry) => $entry->shift_id . ':' . $entry->angel_type_id);
        $types = AngelType::all()->keyBy('id');

        $slots = [];
        foreach ($shifts as $shift) {
            $needs = match (true) {
                !$shift->schedule => $byShift[$shift->id] ?? null,
                (bool) $shift->schedule->needed_from_shift_type => $byShiftType[$shift->shift_type_id] ?? null,
                default => $byLocation[$shift->location_id] ?? null,
            };

            $dayStart = $shift->start->copy()->startOfDay();
            foreach (($needs ?? new Collection())->groupBy('angel_type_id') as $typeId => $typeNeeds) {
                $key = $shift->id . ':' . $typeId;
                $count = (int) $typeNeeds->max('count');
                if (($typeIds !== null && !in_array($typeId, $typeIds)) || (!$count && !isset($taken[$key]))) {
                    continue;
                }

                $slots[] = [
                    'key' => $key,
                    'shift' => $shift,
                    'type' => $types[$typeId],
                    'day' => $shift->start->format('Y-m-d'),
                    'from' => intdiv($shift->start->timestamp - $dayStart->timestamp, 60),
                    'to' => intdiv($shift->end->timestamp - $dayStart->timestamp, 60),
                    'count' => $count,
                    'taken' => $taken[$key] ?? 0,
                ];
            }
        }

        return new Collection($slots);
    }

    /**
     * Collision rule of the guided sign up and the day plan: overlapping shifts collide, except at the same location
     * with the same helper type when they do not have exactly the same times (handover)
     */
    public static function collides(Shift $shift, int $typeId, Shift $other, int $otherTypeId): bool
    {
        if ($shift->id == $other->id) {
            return true;
        }

        if ($shift->start >= $other->end || $other->start >= $shift->end) {
            return false;
        }

        return !(
            $shift->location_id == $other->location_id
            && $typeId == $otherTypeId
            && !($shift->start->timestamp == $other->start->timestamp && $shift->end->timestamp == $other->end->timestamp)
        );
    }
}
