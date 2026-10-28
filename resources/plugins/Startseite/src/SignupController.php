<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\Shifts\ShiftSignupStatus;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Engelsystem\ShiftSignupState;
use Illuminate\Support\Collection;

/**
 * Guided sign up: 1. helper types, 2. days and times, 3. table of the matching time slots to tick
 *
 * The choices of step 1 and 2 are saved, days, times and helper types with own shift entries can not be removed.
 * Uses the same permission checks as the shift entries of the core (Shift_signup_allowed(), Shift_signout_allowed())
 */
class SignupController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'startseite.signup',
    ];

    /** @var Collection|UserAngelType[]|null Memberships of the user by angel type id */
    protected ?Collection $memberships = null;

    /** @var bool[] Can manage by angel type id */
    protected array $manage = [];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response
    ) {
    }

    /**
     * Step 1: helper types
     */
    public function types(): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $slots = $this->slots();
        $locked = $this->lockedTypes($user, $slots);

        $types = [];
        foreach ($slots->groupBy(fn(array $slot) => $slot['type']->id) as $typeSlots) {
            /** @var AngelType $type */
            $type = $typeSlots[0]['type'];
            $reason = $this->typeReason($type);
            $types[] = [
                'type' => $type,
                'locations' => $typeSlots->map(fn(array $slot) => $slot['shift']->location->name)
                    ->unique()
                    ->sort(fn($a, $b) => strnatcasecmp($a, $b))
                    ->implode(', '),
                'checked' => in_array($type->id, $choice->angel_types) || in_array($type->id, $locked),
                'locked' => in_array($type->id, $locked),
                'reason' => in_array($type->id, $locked) ? null : $reason,
            ];
        }
        usort($types, fn($a, $b) => strnatcasecmp(
            $this->sortKey($a['type']->name),
            $this->sortKey($b['type']->name)
        ));

        return $this->response->withView('startseite/signup-types', [
            'types' => $types,
            'step' => 1,
        ]);
    }

    public function saveTypes(Request $request): Response
    {
        $user = $this->auth->user();
        $slots = $this->slots();
        $locked = $this->lockedTypes($user, $slots);
        $available = $slots->map(fn(array $slot) => $slot['type'])->unique('id')
            ->filter(fn(AngelType $type) => !$this->typeReason($type))
            ->pluck('id')
            ->all();

        $selected = array_map('intval', $request->request->all('types'));
        $selected = array_values(array_unique(array_merge(
            array_intersect($selected, $available),
            $locked
        )));

        if (!$selected) {
            $this->addNotification('startseite.signup.no_types', NotificationType::ERROR);
            return $this->response->redirectTo(url('/startseite/eintragen'));
        }

        $choice = $this->choice($user);
        $choice->angel_types = $selected;
        $choice->save();

        return $this->response->redirectTo(url('/startseite/eintragen/tage'));
    }

    /**
     * Step 2: days and times per day
     */
    public function days(): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        if (!$choice->angel_types) {
            return $this->response->redirectTo(url('/startseite/eintragen'));
        }

        $days = $this->dayOptions($user, $this->slots($choice->angel_types));
        foreach ($days as $day => $options) {
            $saved = $choice->days[$day] ?? null;
            $days[$day]['checked'] = $saved || $options['locked'];
            $days[$day]['from'] = $this->clampFrom($options, $saved['from'] ?? null);
            $days[$day]['to'] = $this->clampTo($options, $saved['to'] ?? null);
        }

        return $this->response->withView('startseite/signup-days', [
            'days' => $days,
            'step' => 2,
        ]);
    }

    public function saveDays(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $options = $this->dayOptions($user, $this->slots($choice->angel_types));

        $checked = $request->request->all('days');
        $from = $request->request->all('from');
        $to = $request->request->all('to');

        $days = [];
        foreach ($options as $day => $option) {
            if (!isset($checked[$day]) && !$option['locked']) {
                continue;
            }

            $dayFrom = $this->clampFrom($option, $from[$day] ?? null);
            $dayTo = $this->clampTo($option, $to[$day] ?? null);
            if ($this->minutes($dayFrom) >= $this->minutes($dayTo)) {
                $this->addNotification(
                    __('startseite.signup.invalid_time', [$option['label']]),
                    NotificationType::ERROR
                );
                return $this->response->redirectTo(url('/startseite/eintragen/tage'));
            }

            $days[$day] = ['from' => $dayFrom, 'to' => $dayTo];
        }

        if (!$days) {
            $this->addNotification('startseite.signup.no_days', NotificationType::ERROR);
            return $this->response->redirectTo(url('/startseite/eintragen/tage'));
        }

        $choice->days = $days;
        $choice->save();

        return $this->response->redirectTo(url('/startseite/eintragen/auswahl'));
    }

    /**
     * Step 3: one table per helper type, a row per time slot, a column per day
     */
    public function table(): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        if (!$choice->angel_types || !$choice->days) {
            return $this->response->redirectTo(url($choice->angel_types ? '/startseite/eintragen/tage' : '/startseite/eintragen'));
        }

        $cells = $this->cells($user, $choice);
        $locale = (string) session()->get('locale', 'de_DE');

        $tables = [];
        foreach ($cells->groupBy(fn(array $cell) => $cell['type']->id) as $typeCells) {
            /** @var AngelType $type */
            $type = $typeCells[0]['type'];
            $days = [];
            $rows = [];
            foreach ($typeCells->sortBy(fn(array $cell) => sprintf('%05d-%05d', $cell['from'], $cell['to'])) as $cell) {
                $days[$cell['day']] = Carbon::createFromFormat('Y-m-d', $cell['day'])
                    ->locale($locale)
                    ->isoFormat('dd, DD.MM.');
                $row = $this->time($cell['from']) . ' – ' . $this->time($cell['to']);
                $rows[$row]['cells'][$cell['day']][] = $cell;
                // Rows with only full or blocked slots can be hidden
                $rows[$row]['active'] = ($rows[$row]['active'] ?? false) || $cell['checked'] || !$cell['disabled'];
            }
            ksort($days);

            $tables[] = [
                'type' => $type,
                'locations' => $typeCells->map(fn(array $cell) => $cell['shift']->location->name)
                    ->unique()
                    ->implode(', '),
                'days' => $days,
                'rows' => $rows,
            ];
        }
        usort($tables, fn($a, $b) => strnatcasecmp(
            $this->sortKey($a['type']->name),
            $this->sortKey($b['type']->name)
        ));

        return $this->response->withView('startseite/signup-table', [
            'tables' => $tables,
            'step' => 3,
        ]);
    }

    /**
     * Signs off from unticked own slots and signs up for the newly ticked ones
     */
    public function saveTable(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $ticked = array_flip(array_map('strval', $request->request->all('slots')));

        $cells = $this->cells($user, $choice);
        $userShifts = new Collection(Shifts_by_user($user->id)->all());
        $added = 0;
        $removed = 0;
        $errors = [];

        // Sign off first, so the freed time can be used by the new slots
        foreach ($cells as $cell) {
            if (!$cell['own'] || isset($ticked[$cell['key']]) || $cell['disabled']) {
                continue;
            }

            /** @var ShiftEntry $entry */
            $entry = $cell['own'];
            $entry->delete();
            ShiftEntry_onDelete($entry);
            $userShifts = $userShifts->reject(fn(Shift $shift) => $shift->id == $entry->shift_id)->values();
            $removed++;
        }

        foreach ($cells->sortBy(fn(array $cell) => $cell['shift']->start->timestamp) as $cell) {
            if ($cell['own'] || !isset($ticked[$cell['key']])) {
                continue;
            }

            /** @var Shift $shift */
            $shift = $cell['shift'];
            /** @var AngelType $type */
            $type = $cell['type'];
            $state = $this->signupState($user, $cell, $userShifts);
            $free = $cell['count'] - ShiftEntry::whereShiftId($shift->id)
                ->where('angel_type_id', $type->id)
                ->whereNull('freeloaded_by')
                ->count();

            if (!$state->isSignupAllowed() || $free <= 0 || $user->isFreeloader()) {
                $errors[] = __('startseite.signup.failed', [
                    $type->name,
                    $shift->start->format('d.m.Y H:i') . ' – ' . $shift->end->format('H:i'),
                    $free <= 0 ? __('This shift is already occupied.') : $this->stateMessage($state),
                ]);
                continue;
            }

            // Like the core: join open angel types automatically on sign up
            if (!$type->restricted && !$this->membership($user, $type)) {
                $userAngelType = new UserAngelType();
                $userAngelType->user()->associate($user);
                $userAngelType->angelType()->associate($type);
                $userAngelType->save();
                $this->memberships = null;
            }

            $entry = new ShiftEntry();
            $entry->shift()->associate($shift);
            $entry->angelType()->associate($type);
            $entry->user()->associate($user);
            $entry->save();
            ShiftEntry_onCreate($entry);

            // Further slots of this request collide with this one
            $userShifts->push((clone $shift)->forceFill(['angel_type_id' => $type->id]));
            $added++;
        }

        if ($added || $removed) {
            $this->addNotification(__('startseite.signup.saved', [$added, $removed]));
        } elseif (!$errors) {
            $this->addNotification('startseite.signup.unchanged');
        }
        foreach ($errors as $error) {
            $this->addNotification($error, NotificationType::ERROR);
        }

        return $this->response->redirectTo(url($errors ? '/startseite/eintragen/auswahl' : '/news'));
    }

    protected function choice(User $user): SignupChoice
    {
        return SignupChoice::findOrNew($user->id)->forceFill(['user_id' => $user->id]);
    }

    /**
     * Needed helper types of all shifts that have not ended yet
     *
     * Same sources as NeededAngelTypes_by_shift(), but loaded for all shifts at once
     *
     * @param int[]|null $typeIds Only these helper types
     * @return Collection|array[] [['key', 'shift', 'type', 'day', 'from', 'to', 'count', 'taken']]
     */
    protected function slots(?array $typeIds = null): Collection
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
     * Slots matching the choice with their state for the user
     *
     * @return Collection|array[] Slots with ['own' => ?ShiftEntry, 'checked', 'disabled', 'free', 'reason']
     */
    protected function cells(User $user, SignupChoice $choice): Collection
    {
        $own = ShiftEntry::where('user_id', $user->id)->get()->keyBy('shift_id');
        $userShifts = new Collection(Shifts_by_user($user->id)->all());

        return $this->slots($choice->angel_types)
            ->filter(function (array $slot) use ($choice) {
                $day = $choice->days[$slot['day']] ?? null;
                return $day
                    && $slot['from'] >= $this->minutes($day['from'])
                    && $slot['to'] <= $this->minutes($day['to']);
            })
            ->map(function (array $slot) use ($user, $own, $userShifts) {
                /** @var ShiftEntry|null $entry */
                $entry = $own[$slot['shift']->id] ?? null;
                $free = max(0, $slot['count'] - $slot['taken']);

                if ($entry && $entry->angel_type_id == $slot['type']->id) {
                    $canSignoff = Shift_signout_allowed($slot['shift'], $slot['type'], $user->id);
                    return $slot + [
                        'own' => $entry,
                        'checked' => true,
                        'disabled' => !$canSignoff,
                        'free' => $free,
                        'reason' => $canSignoff ? null : __('startseite.signup.no_signoff'),
                    ];
                }

                $reason = null;
                if ($entry) {
                    $reason = __('startseite.signup.other_type');
                } elseif ($free <= 0) {
                    // Full slots are greyed out for everybody, also for admins
                    $reason = __('startseite.signup.full');
                } else {
                    $state = $this->signupState($user, $slot, $userShifts);
                    $reason = $state->isSignupAllowed() ? null : $this->stateMessage($state);
                }

                return $slot + [
                    'own' => null,
                    'checked' => false,
                    'disabled' => (bool) $reason,
                    'free' => $free,
                    'reason' => $reason,
                ];
            })
            ->values();
    }

    /**
     * Sign up state of the user for a slot, treating open helper types as joined (they are joined on sign up)
     */
    protected function signupState(User $user, array $slot, Collection $userShifts): ShiftSignupState
    {
        /** @var Shift $shift */
        $shift = $slot['shift'];
        /** @var AngelType $type */
        $type = $slot['type'];

        $userAngelType = $this->membership($user, $type);
        if (!$userAngelType && !$type->restricted) {
            $userAngelType = ['angel_type_id' => $type->id];
        }

        return Shift_signup_allowed(
            $user,
            $shift,
            $type,
            $userAngelType,
            $this->collidingShifts($userShifts, $shift, $type),
            (new AngelType())->forceFill(['count' => $slot['count']]),
            ShiftEntry::whereShiftId($shift->id)->where('angel_type_id', $type->id)->get()
        );
    }

    /**
     * Shifts of the user to check for collisions, same rules as in the day plan:
     * Overlapping shifts at the same location with the same helper type are allowed,
     * as long as they do not have exactly the same start and end time
     */
    protected function collidingShifts(Collection $userShifts, Shift $shift, AngelType $type): Collection
    {
        $shifts = $this->canManage($type) ? new Collection() : $userShifts->reject(
            fn(Shift $other) => $other->location_id == $shift->location_id
                && $other->angel_type_id == $type->id
                && !(
                    $other->start->timestamp == $shift->start->timestamp
                    && $other->end->timestamp == $shift->end->timestamp
                )
        )->values();

        // The core reloads all shifts of the user for an empty list, so use a shift that collides with nothing
        if ($shifts->isEmpty()) {
            $shifts->push((new Shift())->forceFill(['id' => 0, 'start' => '1970-01-01', 'end' => '1970-01-01']));
        }

        return $shifts;
    }

    /**
     * Why the user can not choose the helper type, null if they can
     */
    protected function typeReason(AngelType $type): ?string
    {
        if ($this->canManage($type)) {
            return null;
        }

        if (!$type->shift_self_signup) {
            return __('startseite.signup.type_no_self_signup');
        }

        $membership = $this->membership($this->auth->user(), $type);
        if ($type->restricted && !$membership?->confirm_user_id) {
            return $membership
                ? __('startseite.signup.type_pending')
                : __('startseite.signup.type_restricted');
        }

        return null;
    }

    /**
     * Helper types the user has upcoming shift entries in
     *
     * @return int[]
     */
    protected function lockedTypes(User $user, Collection $slots): array
    {
        $shiftIds = $slots->map(fn(array $slot) => $slot['shift']->id)->unique();

        return ShiftEntry::where('user_id', $user->id)
            ->whereIn('shift_id', $shiftIds)
            ->pluck('angel_type_id')
            ->unique()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Days with slots, their possible start and end times and the range of the own shift entries
     *
     * @return array[] [Y-m-d => ['label', 'starts' => string[], 'ends' => string[], 'locked', 'lock_from', 'lock_to']]
     */
    protected function dayOptions(User $user, Collection $slots): array
    {
        $own = ShiftEntry::where('user_id', $user->id)->pluck('angel_type_id', 'shift_id');
        $locale = (string) session()->get('locale', 'de_DE');

        $days = [];
        foreach ($slots->groupBy('day')->sortKeys() as $day => $daySlots) {
            $mine = $daySlots->filter(fn(array $slot) => ($own[$slot['shift']->id] ?? null) == $slot['type']->id);
            $starts = $daySlots->pluck('from')->unique()->sort()->values();
            $ends = $daySlots->pluck('to')->unique()->sort()->values();

            $days[$day] = [
                'label' => Carbon::createFromFormat('Y-m-d', $day)->locale($locale)->isoFormat('dddd, DD.MM.YYYY'),
                'starts' => $starts->map(fn($minutes) => $this->time($minutes))->all(),
                'ends' => $ends->map(fn($minutes) => $this->time($minutes))->all(),
                'locked' => $mine->isNotEmpty(),
                'lock_from' => $mine->isNotEmpty() ? $this->time($mine->min('from')) : null,
                'lock_to' => $mine->isNotEmpty() ? $this->time($mine->max('to')) : null,
            ];
        }

        return $days;
    }

    /**
     * A possible start time, not after the first own shift entry of the day, default the first one
     */
    protected function clampFrom(array $day, ?string $from): string
    {
        $starts = $day['lock_from']
            ? array_filter($day['starts'], fn($time) => $this->minutes($time) <= $this->minutes($day['lock_from']))
            : $day['starts'];

        return in_array($from, $starts, true) ? $from : reset($starts);
    }

    /**
     * A possible end time, not before the last own shift entry of the day, default the last one
     */
    protected function clampTo(array $day, ?string $to): string
    {
        $ends = $day['lock_to']
            ? array_filter($day['ends'], fn($time) => $this->minutes($time) >= $this->minutes($day['lock_to']))
            : $day['ends'];

        return in_array($to, $ends, true) ? $to : end($ends);
    }

    protected function membership(User $user, AngelType $type): ?UserAngelType
    {
        $this->memberships ??= UserAngelType::whereUserId($user->id)->get()->keyBy('angel_type_id');

        return $this->memberships[$type->id] ?? null;
    }

    protected function canManage(AngelType $type): bool
    {
        return $this->manage[$type->id] ??= $this->auth->can('user_shifts_admin')
            || $this->auth->can('user.angeltypes.edit')
            || $this->auth->user()->isAngelTypeSupporter($type);
    }

    protected function stateMessage(ShiftSignupState $state): string
    {
        return match ($state->getState()) {
            ShiftSignupStatus::ANGELTYPE => __('You need be accepted member of the angel type.'),
            ShiftSignupStatus::COLLIDES => __('startseite.signup.collides'),
            ShiftSignupStatus::OCCUPIED => __('startseite.signup.full'),
            ShiftSignupStatus::SHIFT_ENDED => __('This shift ended already.'),
            ShiftSignupStatus::NOT_ARRIVED => __('You are not marked as arrived.'),
            ShiftSignupStatus::NOT_YET => __('You are not allowed to sign up yet.'),
            ShiftSignupStatus::SIGNED_UP => __('You are signed up for this shift.'),
            default => __('startseite.signup.not_allowed'),
        };
    }

    /**
     * Same order as in the day plan if it is installed
     */
    protected function sortKey(string $name): string
    {
        return class_exists(\Helfersystem\Tagesplan\Controller::class)
            ? \Helfersystem\Tagesplan\Controller::sortKey($name)
            : $name;
    }

    /**
     * Minutes since midnight as H:i, after midnight of the next day as 24:00 and later
     */
    protected function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    protected function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hours * 60 + $minutes;
    }
}
