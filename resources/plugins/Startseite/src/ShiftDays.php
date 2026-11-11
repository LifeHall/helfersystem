<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;

/**
 * Shift entries of a user grouped by day, for the start page, the PDF and the mails
 */
class ShiftDays
{
    /**
     * @param string|null $locale For the day names, default the one of the session
     * @return array[] [['label' => string, 'hours' => float, 'entries' => array[]]]
     */
    public static function forUser(User $user, ?string $locale = null): array
    {
        $locale ??= (string) session()->get('locale', 'de_DE');
        $now = Carbon::now();

        $entries = ShiftEntry::with(['shift.location', 'shift.shiftType', 'angelType'])
            ->where('user_id', $user->id)
            ->get()
            ->sortBy(fn(ShiftEntry $entry) => $entry->shift->start->timestamp . '-' . $entry->shift->location->name);

        $days = [];
        foreach ($entries as $entry) {
            $shift = $entry->shift;
            $day = $shift->start->format('Y-m-d');
            $hours = ($shift->end->timestamp - $shift->start->timestamp) / 3600;

            $days[$day] ??= [
                'label' => $shift->start->copy()->locale($locale)->isoFormat('dddd, DD.MM.YYYY'),
                'hours' => 0,
                'entries' => [],
            ];
            $days[$day]['hours'] += $hours;
            $days[$day]['entries'][] = [
                'time' => $shift->start->format('H:i') . ' – ' . $shift->end->format('H:i'),
                'location' => $shift->location->name,
                'angel_type' => $entry->angelType->name,
                'title' => $shift->title,
                'hours' => $hours,
                'past' => $shift->end < $now,
                'shift_id' => $shift->id,
            ];
        }

        return array_values($days);
    }
}
