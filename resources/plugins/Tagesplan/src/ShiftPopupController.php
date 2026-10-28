<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\Shifts\ShiftSignupStatus;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Engelsystem\ShiftSignupState;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Popup of the day plan: shift and location details, sign up / sign off
 *
 * Uses the same permission checks as the shift entries controller of the core
 */
class ShiftPopupController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'tagesplan.view',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
        protected EngelsystemMailer $mailer,
        protected LoggerInterface $log
    ) {
    }

    public function show(Request $request): Response
    {
        $shift = Shift::with(['location', 'shiftType'])->findOrFail((int) $request->input('shift_id'));
        $user = $this->auth->user();
        $locale = (string) session()->get('locale', 'de_DE');

        $types = [];
        foreach (NeededAngelTypes_by_shift($shift) as $need) {
            $angelType = AngelType::findOrFail($need['angel_type_id']);
            $entries = collect($need['shift_entries'])->sortBy('id')->values();
            $selfSignedUp = $entries->contains(fn(ShiftEntry $entry) => $entry->user_id == $user->id);
            $state = $selfSignedUp ? null : $this->selfSignupState($user, $shift, $angelType);

            // Angel types requiring confirmation: offer to request it or show that it is pending
            $membership = null;
            if ($state?->getState() === ShiftSignupStatus::ANGELTYPE && $angelType->restricted) {
                $userAngelType = UserAngelType::whereUserId($user->id)
                    ->where('angel_type_id', $angelType->id)
                    ->first();
                $membership = $userAngelType ? 'pending' : 'none';
            }

            $types[] = [
                'angel_type' => $angelType,
                'count' => (int) $need['count'],
                'free' => max(0, (int) $need['count'] - $entries->count()),
                'entries' => $entries->map(fn(ShiftEntry $entry) => [
                    'entry' => $entry,
                    'can_signoff' => Shift_signout_allowed($shift, $angelType, $entry->user_id),
                ])->all(),
                'self_signed_up' => $selfSignedUp,
                'self_allowed' => $state?->isSignupAllowed() ?? false,
                'self_reason' => $state && !$state->isSignupAllowed() ? $this->stateMessage($state) : null,
                'membership' => $membership,
                'users' => $this->selectableUsers($angelType, $entries->pluck('user_id')->all()),
            ];
        }

        // Same order as in the day plan
        usort($types, fn($a, $b) => strnatcasecmp(
            Controller::sortKey($a['angel_type']->name),
            Controller::sortKey($b['angel_type']->name)
        ));

        return $this->response->withView('tagesplan/shift', [
            'shift' => $shift,
            'location' => $shift->location,
            'day_label' => $shift->start->copy()->locale($locale)->isoFormat('dddd, DD.MM.YYYY'),
            'time_label' => $shift->start->format('H:i') . ' – ' . $shift->end->format('H:i'),
            'types' => $types,
        ]);
    }

    public function signup(Request $request): Response
    {
        $shift = Shift::findOrFail((int) $request->postData('shift_id'));
        $angelType = AngelType::findOrFail((int) $request->postData('angel_type_id'));
        $user = $this->auth->user();
        $target = $request->postData('user_id') ? User::findOrFail((int) $request->postData('user_id')) : $user;

        if (!NeededAngeltype_by_Shift_and_Angeltype($shift, $angelType)) {
            return $this->back($shift, __('tagesplan.not_needed'), NotificationType::ERROR);
        }

        if (ShiftEntry::whereShiftId($shift->id)->where('user_id', $target->id)->exists()) {
            return $this->back(
                $shift,
                __('tagesplan.already_signed_up', [$target->displayName]),
                NotificationType::ERROR
            );
        }

        if ($target->id != $user->id) {
            if (!$this->canManage($angelType)) {
                return $this->back($shift, __('tagesplan.not_allowed'), NotificationType::ERROR);
            }

            if (!$this->auth->can('user_shifts_admin')) {
                if (!$target->userAngelTypes()->wherePivot('angel_type_id', $angelType->id)->exists()) {
                    return $this->back($shift, __('User is not in angel type.'), NotificationType::ERROR);
                }
                $free = (int) NeededAngeltype_by_Shift_and_Angeltype($shift, $angelType)['count']
                    - $shift->shiftEntries()->where('angel_type_id', $angelType->id)->count();
                if ($free <= 0) {
                    return $this->back($shift, __('This shift is already occupied.'), NotificationType::ERROR);
                }
            }
        } else {
            if ($user->isFreeloader()) {
                return $this->back($shift, __('tagesplan.not_allowed'), NotificationType::ERROR);
            }

            $state = $this->selfSignupState($user, $shift, $angelType);
            if (!$state->isSignupAllowed()) {
                return $this->back($shift, $this->stateMessage($state), NotificationType::ERROR);
            }

            // Like the core: join open angel types automatically on sign up
            if (
                !$angelType->restricted
                && !$angelType->userAngelTypes()->wherePivot('user_id', $user->id)->exists()
            ) {
                $userAngelType = new UserAngelType();
                $userAngelType->user()->associate($user);
                $userAngelType->angelType()->associate($angelType);
                $userAngelType->save();
            }
        }

        $shiftEntry = new ShiftEntry();
        $shiftEntry->shift()->associate($shift);
        $shiftEntry->angelType()->associate($angelType);
        $shiftEntry->user()->associate($target);
        $shiftEntry->save();
        ShiftEntry_onCreate($shiftEntry);

        return $this->back(
            $shift,
            $target->id == $user->id
                ? __('You are subscribed. Thank you!')
                : sprintf(__('%s has been subscribed to the shift.'), $target->displayName)
        );
    }

    public function signoff(Request $request): Response
    {
        $shiftEntry = ShiftEntry::with(['shift', 'angelType', 'user'])
            ->findOrFail((int) $request->postData('shift_entry_id'));
        $shift = $shiftEntry->shift;

        if (!Shift_signout_allowed($shift, $shiftEntry->angelType, $shiftEntry->user_id)) {
            return $this->back(
                $shift,
                __('You are not allowed to remove this shift entry. If necessary, ask your supporter or heaven to do so.'),
                NotificationType::ERROR
            );
        }

        $shiftEntry->delete();
        ShiftEntry_onDelete($shiftEntry);

        return $this->back($shift, __('Shift entry removed.'));
    }

    /**
     * Request approval for an angel type that requires confirmation and notify the approvers
     */
    public function requestApproval(Request $request): Response
    {
        $shift = Shift::findOrFail((int) $request->postData('shift_id'));
        $angelType = AngelType::findOrFail((int) $request->postData('angel_type_id'));
        $user = $this->auth->user();

        if (UserAngelType::whereUserId($user->id)->where('angel_type_id', $angelType->id)->exists()) {
            return $this->afterApproval($request, $shift, __('tagesplan.confirmation_pending'));
        }

        $userAngelType = new UserAngelType();
        $userAngelType->user()->associate($user);
        $userAngelType->angelType()->associate($angelType);
        $userAngelType->save();

        engelsystem_log(sprintf(
            'User %s joined %s.',
            User_Nick_render($user, true),
            AngelType_name_render($angelType, true)
        ));

        if ($angelType->restricted) {
            $this->notifyApprovers($user, $angelType);
        }

        return $this->afterApproval($request, $shift, __('tagesplan.approval_requested', [$angelType->name]));
    }

    public function script(): Response
    {
        return $this->response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withContent(file_get_contents(__DIR__ . '/../assets/tagesplan.js'));
    }

    /**
     * Sign up state of the current user, treating open angel types as joined (they are joined on sign up)
     */
    protected function selfSignupState(User $user, Shift $shift, AngelType $angelType): ShiftSignupState
    {
        $neededAngelType = (new AngelType())->forceFill(
            NeededAngeltype_by_Shift_and_Angeltype($shift, $angelType) ?: []
        );
        $shiftEntries = $shift->shiftEntries()->where('angel_type_id', $angelType->id)->get();

        $userAngelType = UserAngelType::whereUserId($user->id)->where('angel_type_id', $angelType->id)->first();
        if (!$userAngelType && !$angelType->restricted) {
            $userAngelType = ['angel_type_id' => $angelType->id];
        }

        return Shift_signup_allowed(
            $user,
            $shift,
            $angelType,
            $userAngelType,
            $this->collidingShifts($user, $shift, $angelType),
            $neededAngelType,
            $shiftEntries
        );
    }

    /**
     * Shifts of the user to check for collisions
     *
     * Overlapping shifts at the same location with the same angel type are allowed,
     * as long as they do not have exactly the same start and end time.
     * Shift coordinators may sign up for parallel shifts, like they may sign up others
     */
    protected function collidingShifts(User $user, Shift $shift, AngelType $angelType): Collection
    {
        $userShifts = $this->canManage($angelType) ? new Collection() : Shifts_by_user($user->id)->reject(
            fn(Shift $other) => $other->location_id == $shift->location_id
                && $other->angel_type_id == $angelType->id
                && !(
                    $other->start->timestamp == $shift->start->timestamp
                    && $other->end->timestamp == $shift->end->timestamp
                )
        )->values();

        // The core reloads all shifts of the user for an empty list, so use a shift that collides with nothing
        if ($userShifts->isEmpty()) {
            $userShifts->push((new Shift())->forceFill(['id' => 0, 'start' => '1970-01-01', 'end' => '1970-01-01']));
        }

        return $userShifts;
    }

    protected function stateMessage(ShiftSignupState $state): string
    {
        return match ($state->getState()) {
            ShiftSignupStatus::ANGELTYPE => __('You need be accepted member of the angel type.'),
            ShiftSignupStatus::COLLIDES => __('This shift collides with one of your shifts.'),
            ShiftSignupStatus::OCCUPIED => __('This shift is already occupied.'),
            ShiftSignupStatus::SHIFT_ENDED => __('This shift ended already.'),
            ShiftSignupStatus::NOT_ARRIVED => __('You are not marked as arrived.'),
            ShiftSignupStatus::NOT_YET => __('You are not allowed to sign up yet.'),
            ShiftSignupStatus::SIGNED_UP => __('You are signed up for this shift.'),
            default => __('tagesplan.not_allowed'),
        };
    }

    protected function canManage(AngelType $angelType): bool
    {
        return $this->auth->can('user_shifts_admin')
            || $this->auth->can('user.angeltypes.edit')
            || $this->auth->user()->isAngelTypeSupporter($angelType);
    }

    /**
     * Users that may be signed up by the current user: all for admins, members of the angel type for supporters
     *
     * @param int[] $signedUp
     * @return array [user_id => display name]
     */
    protected function selectableUsers(AngelType $angelType, array $signedUp): array
    {
        if (!$this->canManage($angelType)) {
            return [];
        }

        $users = $this->auth->can('user_shifts_admin')
            ? User::with('personalData')->get()
            : $angelType->userAngelTypes()->with('personalData')->get();

        return $users
            ->reject(fn(User $user) => in_array($user->id, $signedUp))
            ->mapWithKeys(fn(User $user) => [$user->id => $user->displayName])
            ->sort(fn($a, $b) => strnatcasecmp($a, $b))
            ->all();
    }

    /**
     * Mails everybody who can confirm the request: users allowed to edit angel types and supporters of the type
     */
    protected function notifyApprovers(User $requester, AngelType $angelType): void
    {
        $editors = User::whereHas(
            'groups.privileges',
            fn($query) => $query->where('name', 'user.angeltypes.edit')
        )->get();
        $supporters = $angelType->userAngelTypes()->wherePivot('supporter', true)->get();

        $recipients = $editors->merge($supporters)
            ->unique('id')
            ->reject(fn(User $user) => $user->id == $requester->id);

        foreach ($recipients as $recipient) {
            try {
                $this->mailer->sendViewTranslated(
                    $recipient,
                    'tagesplan.mail.subject',
                    'emails/tagesplan-approval',
                    [
                        // The first two values fill the placeholders of the subject
                        'requester' => $requester->displayName,
                        'angeltype_name' => $angelType->name,
                        'username' => $recipient->displayName,
                        'angeltype' => $angelType,
                    ]
                );
            } catch (Throwable $e) {
                $this->log->error('Tagesplan: approval mail to {user} failed: {error}', [
                    'user' => $recipient->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Requests from the popup script only need the notification, others are redirected back
     */
    protected function afterApproval(Request $request, Shift $shift, string $message): Response
    {
        if ($request->headers->get('X-Tagesplan')) {
            $this->addNotification($message);
            return $this->response->withStatus(204);
        }

        return $this->back($shift, $message);
    }

    protected function back(
        Shift $shift,
        string $message,
        NotificationType $type = NotificationType::MESSAGE
    ): Response {
        $this->addNotification($message, $type);

        return $this->response->redirectTo(url('/tagesplan', [
            'day' => $shift->start->format('Y-m-d'),
            'shift' => $shift->id,
        ]));
    }
}
