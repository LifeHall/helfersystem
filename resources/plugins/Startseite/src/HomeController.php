<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Config\Config;
use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\News;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Start page (replaces the news overview): own shifts, guided sign up and the pinned news in their order
 */
class HomeController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'news',
        'pin' => 'admin_news',
        'move' => 'admin_news',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
        protected Config $config,
        protected News $news
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        $days = $this->shiftDays($user);

        return $this->response->withView('startseite/index', [
            'news' => $this->pinnedNews(),
            // Admins can pin the other news from here
            'unpinned' => $this->auth->can('admin_news')
                ? $this->news->newQuery()
                    ->where('is_pinned', false)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get()
                : [],
            'shift_days' => $days,
            'shift_count' => array_sum(array_map(fn($day) => count($day['entries']), $days)),
            'shift_hours' => array_sum(array_map(fn($day) => $day['hours'], $days)),
            'has_choice' => SignupChoice::whereKey($user->id)->exists(),
            'is_overview' => true,
        ]);
    }

    /**
     * Pins (at the end) or unpins a news
     */
    public function pin(Request $request): Response
    {
        $news = $this->news->findOrFail((int) $request->postData('news_id'));
        $pin = (bool) $request->postData('pin');

        // Without touching updated_at, pinning is no change of the news itself
        $this->news->newQuery()->whereKey($news->id)->toBase()->update(['is_pinned' => $pin]);

        if ($pin) {
            NewsOrder::updateOrCreate(
                ['news_id' => $news->id],
                ['position' => (int) NewsOrder::max('position') + 1]
            );
        } else {
            NewsOrder::whereKey($news->id)->delete();
        }

        $this->addNotification($pin ? 'startseite.news.pin.success' : 'startseite.news.unpin.success');

        return $this->response->redirectTo(url('/news'));
    }

    /**
     * Moves a pinned news one position up or down
     */
    public function move(Request $request): Response
    {
        $ids = $this->pinnedNews()->pluck('id')->values()->all();
        $index = array_search((int) $request->postData('news_id'), $ids, true);
        $target = $index === false ? false : $index + ($request->postData('direction') == 'up' ? -1 : 1);

        if ($target !== false && isset($ids[$target])) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        }

        foreach ($ids as $position => $id) {
            NewsOrder::updateOrCreate(['news_id' => $id], ['position' => $position + 1]);
        }

        return $this->response->redirectTo(url('/news') . '#news-' . (int) $request->postData('news_id'));
    }

    /**
     * Own shifts as PDF download
     */
    public function pdf(): Response
    {
        $user = $this->auth->user();
        $pdf = (new ShiftPdf())->render(
            $user->displayName,
            (string) $this->config->get('app_name'),
            $this->shiftDays($user)
        );

        $filename = 'Dienste ' . preg_replace('/[^\w\- ]+/u', '', $user->displayName) . '.pdf';

        return $this->response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . Str::ascii($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
            )
            ->withContent($pdf);
    }

    public function script(): Response
    {
        return $this->response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withContent(file_get_contents(__DIR__ . '/../assets/startseite.js'));
    }

    /**
     * Pinned news in the saved order, news pinned elsewhere (e.g. in the news editor) at the end
     */
    protected function pinnedNews(): Collection
    {
        $positions = NewsOrder::pluck('position', 'news_id');

        return $this->news->newQuery()
            ->with('user')
            ->withCount('comments')
            ->where('is_pinned', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn(News $news) => $positions[$news->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Shift entries of the user grouped by day
     *
     * @return array[] [['label' => string, 'hours' => float, 'entries' => array[]]]
     */
    protected function shiftDays(User $user): array
    {
        $locale = (string) session()->get('locale', 'de_DE');
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
