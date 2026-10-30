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
use Illuminate\Support\Collection;

/**
 * Automatic assignment: after the helper types (step 1) and days (step 2) of the guided sign up,
 * the helper ticks the shift times per day and sets the number of shifts, the system assigns them
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
     * Step 3: shift times per day (a row per time, a column per day) and number of shifts
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
        $slots = $this->slots($choice->angel_types, $days);
        $locale = (string) session()->get('locale', 'de_DE');
        $mainShiftType = Slots::mainShiftType();

        $columns = [];
        $rows = [];
        foreach ($slots->sortBy(fn(array $slot) => sprintf('%05d-%05d', $slot['from'], $slot['to'])) as $slot) {
            $day = $slot['day'];
            $time = AutoWish::timeKey($slot);
            $columns[$day] = Carbon::createFromFormat('Y-m-d', $day)->locale($locale)->isoFormat('dd, DD.MM.');

            $cell = $rows[$time]['cells'][$day] ?? [
                'value' => $time,
                // Before the first save all times are ticked
                'checked' => !$wish || $wish->matches($slot),
                'free' => 0,
                'types' => [],
                'categories' => [],
            ];
            $cell['checked'] = $cell['checked'] || ($wish && $wish->matches($slot));
            $cell['free'] += max(0, $slot['count'] - $slot['taken']);
            $cell['types'][$slot['type']->id] = $slot['type']->name;
            if ($category = Slots::category($slot['shift'], $mainShiftType)) {
                $cell['categories'][$category['name']] = $category;
            }

            $rows[$time]['label'] = $this->time($slot['from']) . ' – ' . $this->time($slot['to']);
            $rows[$time]['cells'][$day] = $cell;
        }
        ksort($columns);

        $categories = [];
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $categories += $cell['categories'];
            }
        }
        ksort($categories);

        return $this->response->withView('startseite/auto-wishes', [
            'columns' => $columns,
            'rows' => $rows,
            'categories' => array_values($categories),
            'count' => min($wish->count ?? 1, max(1, $slots->count())),
            'max' => max(1, $slots->count()),
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

        // Only times that exist on the day
        $ticked = $request->request->all('times');
        $windows = [];
        foreach ($this->slots($choice->angel_types, $days) as $slot) {
            $time = AutoWish::timeKey($slot);
            if (in_array($time, (array) ($ticked[$slot['day']] ?? []), true)) {
                $windows[$slot['day']][$time] = $time;
            }
        }
        if (!$windows) {
            $this->addNotification('startseite.auto.no_times', NotificationType::ERROR);
            return $this->response->redirectTo(url('/startseite/automatisch'));
        }
        ksort($windows);
        $windows = array_map(fn(array $times) => array_values($times), $windows);

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
     * Slots of the chosen helper types on the chosen days
     *
     * @return Collection|array[] See Slots::load()
     */
    protected function slots(array $typeIds, array $days): Collection
    {
        return Slots::load($typeIds)
            ->filter(fn(array $slot) => in_array($slot['day'], $days, true))
            ->values();
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
