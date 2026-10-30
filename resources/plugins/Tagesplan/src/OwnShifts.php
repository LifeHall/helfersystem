<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Support\Collection;

/**
 * Helpers (without tagesplan.manage) only see their own shift entries in the day plan,
 * with config tagesplan_show_colleagues also the people at the same location at the same time
 */
class OwnShifts
{
    /** @var Collection|Shift[] Shifts of the user */
    protected Collection $shifts;

    public function __construct(protected User $user)
    {
        $this->shifts = ShiftEntry::with('shift')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn(ShiftEntry $entry) => $entry->shift)
            ->unique('id')
            ->values();
    }

    /**
     * The user sees only own shifts (and colleagues), managers see everything
     */
    public static function restricted(): bool
    {
        return !auth()->can('tagesplan.manage');
    }

    public static function showColleagues(): bool
    {
        return (bool) config('tagesplan_show_colleagues', false);
    }

    /**
     * Days (Y-m-d) with own shifts
     *
     * @return string[]
     */
    public function days(): array
    {
        return $this->shifts
            ->map(fn(Shift $shift) => $shift->start->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Visible part of the needed angel types of a shift: only filled places, no open ones
     *
     * @param array[] $needs [angel_type_id => ['count' => int, 'entries' => ShiftEntry[], ...]]
     * @return array[] Same format, without the hidden angel types and entries
     */
    public function filter(Shift $shift, array $needs): array
    {
        $colleagues = static::showColleagues() && $this->shifts->contains(
            fn(Shift $own) => $own->location_id == $shift->location_id
                && $own->start < $shift->end
                && $own->end > $shift->start
        );

        $visible = [];
        foreach ($needs as $typeId => $need) {
            $entries = array_values(array_filter(
                $need['entries'],
                fn(ShiftEntry $entry) => $colleagues || $entry->user_id == $this->user->id
            ));
            if (!$entries) {
                continue;
            }

            $visible[$typeId] = ['count' => count($entries), 'entries' => $entries] + $need;
        }

        return $visible;
    }
}
