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
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\Shifts\ShiftSignupStatus;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Engelsystem\ShiftSignupState;
use Illuminate\Support\Collection;

/**
 * Guided sign up: 1. helper types, 2. days, 3. table of the time slots of these days to tick
 *
 * The choices of step 1 and 2 are saved, days and helper types with own shift entries can not be removed.
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

    protected bool $assignerLoaded = false;

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
        protected ApprovalNotifier $notifier,
        protected AutoAssigner $assigner
    ) {
    }

    /**
     * Steps 1 and 2 are shared with the automatic assignment, which goes on to its wishes instead of the table
     */
    protected function isAuto(Request $request): bool
    {
        return $request->input('modus') === 'auto';
    }

    protected function stepUrl(string $path, bool $auto): string
    {
        return url($path, $auto ? ['modus' => 'auto'] : []);
    }

    /**
     * Step 1: helper types
     */
    public function types(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $slots = $this->slots();
        $locked = $this->lockedTypes($user, $slots);

        // Before the first save: the helper types chosen at the registration (or joined later)
        $preset = $choice->exists
            ? $choice->angel_types
            : UserAngelType::whereUserId($user->id)->pluck('angel_type_id')->map(fn($id) => (int) $id)->all();

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
                'checked' => (in_array($type->id, $preset) && !$reason) || in_array($type->id, $locked),
                'locked' => in_array($type->id, $locked),
                'reason' => in_array($type->id, $locked) ? null : $reason,
                'can_request' => $this->canRequest($type),
            ];
        }
        usort($types, fn($a, $b) => strnatcasecmp(
            $this->sortKey($a['type']->name),
            $this->sortKey($b['type']->name)
        ));

        return $this->response->withView('startseite/signup-types', [
            'types' => $types,
            'step' => 1,
            'auto' => $this->isAuto($request),
        ]);
    }

    public function saveTypes(Request $request): Response
    {
        $user = $this->auth->user();
        $selected = $this->selectedTypes($user, $request);

        if (!$selected) {
            $this->addNotification('startseite.signup.no_types', NotificationType::ERROR);
            return $this->response->redirectTo($this->stepUrl('/startseite/eintragen', $this->isAuto($request)));
        }

        $choice = $this->choice($user);
        $choice->angel_types = $selected;
        $choice->save();

        return $this->response->redirectTo($this->stepUrl('/startseite/eintragen/tage', $this->isAuto($request)));
    }

    /**
     * Requests the confirmation for a helper type and notifies the approvers,
     * the helper types ticked so far are kept
     */
    public function requestApproval(Request $request): Response
    {
        $user = $this->auth->user();
        $type = AngelType::findOrFail((int) $request->postData('request'));

        $choice = $this->choice($user);
        $choice->angel_types = $this->selectedTypes($user, $request);
        $choice->save();

        if (!$this->canRequest($type)) {
            $this->addNotification(
                $this->membership($user, $type) ? 'startseite.signup.type_pending' : 'startseite.signup.not_allowed',
                NotificationType::ERROR
            );
            return $this->response->redirectTo($this->stepUrl('/startseite/eintragen', $this->isAuto($request)));
        }

        $userAngelType = new UserAngelType();
        $userAngelType->user()->associate($user);
        $userAngelType->angelType()->associate($type);
        $userAngelType->save();

        engelsystem_log(sprintf(
            'User %s joined %s.',
            User_Nick_render($user, true),
            AngelType_name_render($type, true)
        ));

        $this->notifier->notify($user, $type);
        $this->addNotification(__('startseite.signup.request.success', [$type->name]));

        return $this->response->redirectTo(
            $this->stepUrl('/startseite/eintragen', $this->isAuto($request)) . '#type-' . $type->id
        );
    }

    /**
     * Step 2: days
     */
    public function days(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $auto = $this->isAuto($request);
        if (!$choice->angel_types) {
            return $this->response->redirectTo($this->stepUrl('/startseite/eintragen', $auto));
        }

        $chosen = $this->chosenDays($choice);
        $days = $this->dayOptions($user, $this->slots($choice->angel_types));
        foreach ($days as $day => $options) {
            $days[$day]['checked'] = in_array($day, $chosen, true) || $options['locked'];
        }

        return $this->response->withView('startseite/signup-days', [
            'days' => $days,
            'step' => 2,
            'auto' => $auto,
        ]);
    }

    public function saveDays(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = $this->choice($user);
        $options = $this->dayOptions($user, $this->slots($choice->angel_types));

        $checked = $request->request->all('days');

        $days = [];
        foreach ($options as $day => $option) {
            if (isset($checked[$day]) || $option['locked']) {
                $days[] = $day;
            }
        }

        if (!$days) {
            $this->addNotification('startseite.signup.no_days', NotificationType::ERROR);
            return $this->response->redirectTo($this->stepUrl('/startseite/eintragen/tage', $this->isAuto($request)));
        }

        $choice->days = $days;
        $choice->save();

        return $this->response->redirectTo(url(
            $this->isAuto($request) ? '/startseite/automatisch' : '/startseite/eintragen/auswahl'
        ));
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

        $mainShiftType = Slots::mainShiftType();
        $cells = $cells->map(fn(array $cell) => $cell + ['category' => Slots::category($cell['shift'], $mainShiftType)]);

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
            'categories' => $cells->pluck('category')->filter()->unique('name')->sortBy('name')->values()->all(),
            'rebook' => $cells->contains(fn(array $cell) => $cell['rebook'] ?? false),
            'auto_entries' => $cells->contains(fn(array $cell) => $cell['auto'] ?? false),
            'step' => 3,
        ]);
    }

    /**
     * Signs off from unticked own slots and signs up for the newly ticked ones
     */
    public function saveTable(Request $request): Response
    {
        // Under the lock of the automatic assignment, it may move helpers to make room
        return $this->assigner->run(fn() => $this->saveTableLocked($request));
    }

    protected function saveTableLocked(Request $request): Response
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
            $taken = ShiftEntry::whereShiftId($shift->id)
                ->where('angel_type_id', $type->id)
                ->whereNull('freeloaded_by')
                ->count();
            $free = $cell['count'] - $taken;

            // A full slot is checked as if it had a free place, which is made by moving automatically assigned helpers
            $state = $this->signupState($user, $free > 0 ? $cell : ['count' => $taken + 1] + $cell, $userShifts);
            if ($free <= 0 && $state->isSignupAllowed() && !$user->isFreeloader()) {
                $this->assigner->load();
                $free = $this->assigner->makeRoom($cell['key'], $user->id, array_keys($ticked)) ? 1 : 0;
            }

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

        // Freed places can fulfill wishes of the automatic assignment
        if ($removed) {
            $this->assigner->load();
            $this->assigner->fillOpen();
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
     * @param int[]|null $typeIds Only these helper types
     * @return Collection|array[] See Slots::load()
     */
    protected function slots(?array $typeIds = null): Collection
    {
        return Slots::load($typeIds);
    }

    /**
     * Slots matching the choice with their state for the user
     *
     * @return Collection|array[] Slots with ['own' => ?ShiftEntry, 'checked', 'disabled', 'free', 'reason']
     */
    protected function cells(User $user, SignupChoice $choice): Collection
    {
        $own = ShiftEntry::where('user_id', $user->id)->get()->keyBy('shift_id');
        // Automatically assigned entries can only be changed with the wishes
        $auto = AutoEntry::whereUserId($user->id)->pluck('shift_entry_id')->all();
        $days = $this->chosenDays($choice);
        $slots = $this->slots($choice->angel_types)
            ->filter(fn(array $slot) => in_array($slot['day'], $days, true))
            ->values();

        // Own entries in the table that can be unticked: collisions with them are greyed out by the script,
        // so they are released again when the entry is unticked. Saving checks all own entries again
        $releasable = $slots
            ->filter(fn(array $slot) => ($own[$slot['shift']->id] ?? null)?->angel_type_id == $slot['type']->id
                && !in_array($own[$slot['shift']->id]->id, $auto)
                && Shift_signout_allowed($slot['shift'], $slot['type'], $user->id))
            ->map(fn(array $slot) => $slot['shift']->id)
            ->all();
        $userShifts = (new Collection(Shifts_by_user($user->id)->all()))
            ->reject(fn(Shift $shift) => in_array($shift->id, $releasable))
            ->values();

        return $slots
            ->map(function (array $slot) use ($user, $own, $auto, $userShifts, $releasable) {
                /** @var ShiftEntry|null $entry */
                $entry = $own[$slot['shift']->id] ?? null;
                $free = max(0, $slot['count'] - $slot['taken']);
                $slot['manage'] = $this->canManage($slot['type']);

                if ($entry && $entry->angel_type_id == $slot['type']->id) {
                    $canSignoff = in_array($slot['shift']->id, $releasable);
                    $isAuto = in_array($entry->id, $auto);
                    return $slot + [
                        'own' => $entry,
                        'checked' => true,
                        'disabled' => !$canSignoff,
                        'free' => $free,
                        'reason' => match (true) {
                            $isAuto => __('startseite.signup.auto_entry'),
                            !$canSignoff => __('startseite.signup.no_signoff'),
                            default => null,
                        },
                        'auto' => $isAuto,
                    ];
                }

                $reason = null;
                $rebook = false;
                if ($entry && !in_array($entry->shift_id, $releasable)) {
                    $reason = __('startseite.signup.other_type');
                } elseif ($free <= 0) {
                    // Full slots can only be chosen if automatically assigned helpers can move to make room,
                    // else they are greyed out for everybody, also for admins
                    $rebook = $this->canRebook($user, $slot, $userShifts);
                    $reason = $rebook ? null : __('startseite.signup.full');
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
                    'rebook' => $rebook,
                ];
            });
    }

    /**
     * The user could sign up for the full slot if it had a free place, and automatically assigned helpers
     * can be moved to make one
     */
    protected function canRebook(User $user, array $slot, Collection $userShifts): bool
    {
        $state = $this->signupState($user, ['count' => $slot['taken'] + 1] + $slot, $userShifts);
        if (!$state->isSignupAllowed()) {
            return false;
        }

        if (!$this->assignerLoaded) {
            $this->assigner->load();
            $this->assignerLoaded = true;
        }

        return $this->assigner->canFree($slot['key'], $user->id);
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
     * The helper type requires a confirmation the user can request (not requested yet)
     */
    protected function canRequest(AngelType $type): bool
    {
        return $type->restricted
            && $type->shift_self_signup
            && !$this->canManage($type)
            && !$this->membership($this->auth->user(), $type);
    }

    /**
     * Ticked helper types that can be chosen, with the ones the user has shift entries in
     *
     * @return int[]
     */
    protected function selectedTypes(User $user, Request $request): array
    {
        $slots = $this->slots();
        $available = $slots->map(fn(array $slot) => $slot['type'])->unique('id')
            ->filter(fn(AngelType $type) => !$this->typeReason($type))
            ->pluck('id')
            ->all();

        $selected = array_map('intval', $request->request->all('types'));

        return array_values(array_unique(array_merge(
            array_intersect($selected, $available),
            $this->lockedTypes($user, $slots)
        )));
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
     * Saved days, before version 1.2 they were saved with times: [Y-m-d => ['from' => H:i, 'to' => H:i]]
     *
     * @return string[] Y-m-d
     */
    protected function chosenDays(SignupChoice $choice): array
    {
        $days = $choice->days ?: [];

        return array_values(array_map('strval', array_is_list($days) ? $days : array_keys($days)));
    }

    /**
     * Days with slots, locked if the user has own shift entries on them
     *
     * @return array[] [Y-m-d => ['label', 'locked']]
     */
    protected function dayOptions(User $user, Collection $slots): array
    {
        $own = ShiftEntry::where('user_id', $user->id)->pluck('angel_type_id', 'shift_id');
        $locale = (string) session()->get('locale', 'de_DE');

        $days = [];
        foreach ($slots->groupBy('day')->sortKeys() as $day => $daySlots) {
            $days[$day] = [
                'label' => Carbon::createFromFormat('Y-m-d', $day)->locale($locale)->isoFormat('dddd, DD.MM.YYYY'),
                'locked' => $daySlots->contains(
                    fn(array $slot) => ($own[$slot['shift']->id] ?? null) == $slot['type']->id
                ),
            ];
        }

        return $days;
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
            // Name before the rename in the core (2026-10)
            || $this->auth->can('admin_user_angeltypes')
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
}
