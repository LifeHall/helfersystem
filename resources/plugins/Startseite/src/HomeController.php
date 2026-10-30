<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Config\Config;
use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Http\Redirector;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\News;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
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
        'send' => 'startseite.send_shifts',
        'sendOne' => 'startseite.send_shifts',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
        protected Config $config,
        protected News $news,
        protected ShiftPlanMailer $mailer,
        protected Redirector $redirect
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        $days = ShiftDays::forUser($user);

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
            'upcoming_count' => count(array_filter(
                array_merge(...array_column($days, 'entries')),
                fn(array $entry) => !$entry['past']
            )),
            'shift_hours' => array_sum(array_map(fn($day) => $day['hours'], $days)),
            'has_choice' => SignupChoice::whereKey($user->id)->exists(),
            'wish' => AutoWish::find($user->id),
            'open_wishes' => $this->auth->can('startseite.send_shifts') ? AutoController::openWishes() : 0,
            'recipients' => $this->auth->can('startseite.send_shifts') ? $this->recipients()->count() : 0,
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
            ShiftDays::forUser($user)
        );

        $filename = ShiftPdf::filename($user->displayName);

        return $this->response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . Str::ascii($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
            )
            ->withContent($pdf);
    }

    /**
     * Mails everybody with shift entries their shifts as PDF, in their language
     */
    public function send(): Response
    {
        if (!$this->mailer->isEnabled()) {
            $this->addNotification('startseite.send.disabled', NotificationType::ERROR);
            return $this->response->redirectTo(url('/news'));
        }

        // Many recipients can take a while
        set_time_limit(0);

        $sent = 0;
        $failed = 0;
        foreach ($this->recipients()->with(['contact', 'personalData', 'settings'])->get() as $user) {
            $this->mailer->sendPlan($user) ? $sent++ : $failed++;
        }

        engelsystem_log(sprintf('Sent the shift plans as PDF: %d mails, %d failed', $sent, $failed));
        $this->addNotification(__('startseite.send.success', [$sent]));
        if ($failed) {
            $this->addNotification(__('startseite.send.failed', [$failed]), NotificationType::ERROR);
        }

        return $this->response->redirectTo(url('/news'));
    }

    /**
     * Mails one person (e.g. after changes to their shifts) their shifts as PDF,
     * back to the page with the form (day plan)
     */
    public function sendOne(Request $request): Response
    {
        $user = $this->recipients()
            ->with(['contact', 'personalData', 'settings'])
            ->find((int) $request->postData('user_id'));

        if (!$this->mailer->isEnabled()) {
            $this->addNotification('startseite.send.disabled', NotificationType::ERROR);
        } elseif (!$user) {
            $this->addNotification('startseite.send.no_user', NotificationType::ERROR);
        } elseif ($this->mailer->sendPlan($user)) {
            engelsystem_log(sprintf('Sent the shift plan as PDF to %s', User_Nick_render($user, true)));
            $this->addNotification(__('startseite.send.success_one', [$user->displayName]));
        } else {
            $this->addNotification(__('startseite.send.failed', [1]), NotificationType::ERROR);
        }

        return $this->redirect->back();
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
     * Users with shift entries
     */
    protected function recipients(): Builder
    {
        return User::whereHas('shiftEntries');
    }
}
