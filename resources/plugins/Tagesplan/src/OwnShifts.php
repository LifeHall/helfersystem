<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Support\Collection;

/**
 * Helpers (without tagesplan.manage) see all shifts in the day plan, but only the names of their own entries,
 * with config tagesplan_show_colleagues also of the people at the same location at the same time.
 * Supporters of an angel type see all names of it
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

    public function isUser(int $userId): bool
    {
        return $userId == $this->user->id;
    }

    public function hasShifts(): bool
    {
        return $this->shifts->isNotEmpty();
    }

    public function supports(int $angelTypeId): bool
    {
        return $this->supported->contains('id', $angelTypeId);
    }

    /**
     * The user sees only the names of own shifts (and colleagues), managers see everything
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
     * Managers (tagesplan.manage) see all names
     */
    public static function canManage(User $user): bool
    {
        return $user->privileges->contains('name', 'tagesplan.manage');
    }

    /**
     * The user sees the name of the entry: their own, of a supported angel type or a colleague
     */
    public function sees(Shift $shift, int $angelTypeId, int $userId): bool
    {
        return $userId == $this->user->id || $this->supports($angelTypeId) || $this->isColleague($shift);
    }

    /**
     * With config tagesplan_show_colleagues: the user works at the location of the shift at the same time
     */
    protected function isColleague(Shift $shift): bool
    {
        return static::showColleagues() && $this->shifts->contains(
            fn(Shift $own) => $own->location_id == $shift->location_id
                && $own->start < $shift->end
                && $own->end > $shift->start
        );
    }
}
