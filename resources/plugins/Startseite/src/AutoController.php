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
use Engelsystem\Models\User\User;

/**
 * Automatic assignment: after the helper types (step 1) and days (step 2) of the guided sign up,
 * the helper sets a time window per day and the number of shifts, the system assigns them
 */
class AutoController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'startseite.signup',
        'fill' => 'startseite.send_shifts',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
        protected AutoAssigner $assigner
    ) {
    }

    /**
     * Step 3: time window per day and number of shifts
     */
    public function wishes(): Response
    {
        $user = $this->auth->user();
        $choice = SignupChoice::find($user->id);
        $days = $this->chosenDays($choice);
        if (!$choice?->angel_types || !$days) {
            return $this->response->redirectTo(url('/startseite/eintragen', ['modus' => 'auto']));
        }

        $wish = AutoWish::find($user->id);
        $options = $this->windowOptions($choice->angel_types, $days);
        $locale = (string) session()->get('locale', 'de_DE');

        $rows = [];
        foreach ($options as $day => $option) {
            $saved = $wish?->windows[$day] ?? null;
            $rows[$day] = $option + [
                'label' => Carbon::createFromFormat('Y-m-d', $day)->locale($locale)->isoFormat('dddd, DD.MM.YYYY'),
                'from' => $saved && in_array($saved['from'], $option['starts']) ? $saved['from'] : $option['starts'][0],
                'to' => $saved && in_array($saved['to'], $option['ends']) ? $saved['to'] : end($option['ends']),
            ];
        }

        $max = Slots::load($choice->angel_types)
            ->filter(fn(array $slot) => in_array($slot['day'], $days, true))
            ->count();

        return $this->response->withView('startseite/auto-wishes', [
            'rows' => $rows,
            'count' => min($wish->count ?? 1, max(1, $max)),
            'max' => max(1, $max),
            'has_wish' => (bool) $wish,
            'step' => 3,
            'auto' => true,
        ]);
    }

    /**
     * Saves the wish and assigns the helper right away
     */
    public function save(Request $request): Response
    {
        $user = $this->auth->user();
        $choice = SignupChoice::find($user->id);
        $days = $this->chosenDays($choice);
        if (!$choice?->angel_types || !$days) {
            return $this->response->redirectTo(url('/startseite/eintragen', ['modus' => 'auto']));
        }

        $from = $request->request->all('from');
        $to = $request->request->all('to');
        $windows = [];
        foreach ($this->windowOptions($choice->angel_types, $days) as $day => $option) {
            $start = (int) ($from[$day] ?? -1);
            $end = (int) ($to[$day] ?? -1);
            if (!in_array($start, $option['starts'], true) || !in_array($end, $option['ends'], true) || $start >= $end) {
                $this->addNotification(__('startseite.auto.invalid_window', [$day]), NotificationType::ERROR);
                return $this->response->redirectTo(url('/startseite/automatisch'));
            }
            $windows[$day] = ['from' => $start, 'to' => $end];
        }

        $wish = AutoWish::findOrNew($user->id);
        $wish->user_id = $user->id;
        $wish->angel_types = $choice->angel_types;
        $wish->windows = $windows;
        $wish->count = max(1, min(100, (int) $request->postData('count')));
        $wish->save();

        $result = $this->assigner->run(function (AutoAssigner $assigner) use ($user) {
            $assigner->load();
            $result = $assigner->reassign($user->id);
            // Released places can fulfill other wishes
            if ($result['removed']) {
                $assigner->fillOpen();
            }
            return $result;
        });

        engelsystem_log(sprintf(
            'Automatic assignment for %s: %d of %d shifts (%d added, %d removed)',
            User_Nick_render($user, true),
            $result['have'],
            $result['wanted'],
            $result['added'],
            $result['removed']
        ));
        $this->addNotification(
            __('startseite.auto.result', [$result['have'], $result['wanted']]),
            $result['have'] < $result['wanted'] ? NotificationType::WARNING : NotificationType::MESSAGE
        );
        if ($result['have'] < $result['wanted']) {
            $this->addNotification('startseite.auto.open_info', NotificationType::INFORMATION);
        }

        return $this->response->redirectTo(url('/news'));
    }

    /**
     * Removes the wish, the shifts stay and are no longer moved
     */
    public function delete(): Response
    {
        $user = $this->auth->user();
        AutoWish::whereKey($user->id)->delete();
        AutoEntry::whereUserId($user->id)->delete();
        $this->addNotification('startseite.auto.deleted');

        return $this->response->redirectTo(url('/news'));
    }

    /**
     * Shift coordinators: assigns all open wishes again (e.g. after shifts were added or helpers signed off)
     */
    public function fill(): Response
    {
        $added = $this->assigner->run(function (AutoAssigner $assigner) {
            $assigner->load();
            return $assigner->fillOpen();
        });

        engelsystem_log(sprintf('Automatic assignment of the open wishes: %d shifts added', $added));
        $this->addNotification(__('startseite.auto.filled', [$added]));

        return $this->response->redirectTo(url('/news'));
    }

    /**
     * Wishes with fewer shifts than wanted
     */
    public static function openWishes(): int
    {
        $counts = User::whereIn('id', AutoWish::pluck('user_id'))
            ->withCount(['shiftEntries' => fn($query) => $query->whereHas(
                'shift',
                fn($shift) => $shift->where('end', '>', Carbon::now())
            )])
            ->pluck('shift_entries_count', 'id');

        return AutoWish::all()->filter(fn(AutoWish $wish) => ($counts[$wish->user_id] ?? 0) < $wish->count)->count();
    }

    /**
     * Possible starts and ends of the time window per chosen day, from the slots of the chosen helper types
     *
     * @return array[] [Y-m-d => ['starts' => int[], 'ends' => int[]]] in minutes, sorted
     */
    protected function windowOptions(array $typeIds, array $days): array
    {
        $options = [];
        foreach (Slots::load($typeIds)->groupBy('day') as $day => $slots) {
            if (!in_array($day, $days, true)) {
                continue;
            }

            $starts = $slots->pluck('from')->unique()->sort()->values()->all();
            $ends = $slots->pluck('to')->unique()->sort()->values()->all();
            $options[$day] = [
                'starts' => $starts,
                'ends' => $ends,
                'start_labels' => array_map($this->time(...), $starts),
                'end_labels' => array_map($this->time(...), $ends),
            ];
        }
        ksort($options);

        return $options;
    }

    /**
     * @return string[] Y-m-d, see SignupController::chosenDays()
     */
    protected function chosenDays(?SignupChoice $choice): array
    {
        $days = $choice?->days ?: [];

        return array_values(array_map('strval', array_is_list($days) ? $days : array_keys($days)));
    }

    protected function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
