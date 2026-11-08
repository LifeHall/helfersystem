<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Support\Collection;

/**
 * Helpers (without tagesplan.manage) only see their own shift entries in the day plan,
 * with config tagesplan_show_colleagues also the people at the same location at the same time.
 * Supporters of an angel type see all shifts of it, including the open places
 */
class OwnShifts
{
    /** @var Collection|Shift[] Shifts of the user */
    protected Collection $shifts;

    /** @var Collection|AngelType[] Angel types the user supports */
    protected Collection $supported;

    public function __construct(protected User $user)
    {
        $this->shifts = ShiftEntry::with('shift')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn(ShiftEntry $entry) => $entry->shift)
            ->unique('id')
            ->values();

        $this->supported = $user->userAngelTypes()
            ->wherePivot('supporter', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection|AngelType[]
     */
    public function supported(): Collection
    {
        return $this->supported;
    }

    public function supports(int $angelTypeId): bool
    {
        return $this->supported->contains('id', $angelTypeId);
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
        $shifts = $this->shifts;
        if ($this->supported->isNotEmpty()) {
            // Days of the supported angel types, needed by the shift itself or its location
            $typeIds = $this->supported->pluck('id')->all();
            $needs = fn($query) => $query->whereIn('angel_type_id', $typeIds);
            $shifts = $shifts->concat(
                Shift::whereHas('neededAngelTypes', $needs)
                    ->orWhereHas('location.neededAngelTypes', $needs)
                    ->get(['id', 'start'])
            );
        }

        return $shifts
            ->map(fn(Shift $shift) => $shift->start->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Visible part of the needed angel types of a shift: only filled places, no open ones,
     * supported angel types completely (marked with 'full')
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
            if ($this->supports((int) $typeId)) {
                $visible[$typeId] = ['full' => true] + $need;
                continue;
            }

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
