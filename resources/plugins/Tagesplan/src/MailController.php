<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Http\Redirector;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\User\User;
use Helfersystem\Startseite\MailJob;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mail with a free subject and text to all helpers or one person, each one greeted with their name,
 * optionally with their shift plan and the day plan as PDF
 *
 * Mails to many people are sent in batches by a mail job of the plugin Startseite
 */
class MailController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'tagesplan.mail_all',
    ];

    public function __construct(
        protected Response $response,
        protected EngelsystemMailer $engelsystemMailer,
        protected Redirector $redirect
    ) {
    }

    public function form(): Response
    {
        $users = User::with('personalData')
            ->get()
            ->mapWithKeys(fn(User $user) => [$user->id => MessageJob::fullName($user)])
            ->all();
        natcasesort($users);

        return $this->response->withView('tagesplan/mail', [
            'counts' => [
                'shifts' => $this->recipients('shifts')->count(),
                'all' => $this->recipients('all')->count(),
            ],
            'users' => $users,
            'enabled' => $this->isEnabled() && static::hasMailJobs(),
            'mail_jobs' => static::hasMailJobs() ? MailJob::unfinished() : [],
        ]);
    }

    public function send(Request $request): Response
    {
        $group = $request->postData('recipients');
        $group = in_array($group, ['all', 'one'], true) ? $group : 'shifts';
        $payload = [
            'subject' => trim((string) $request->postData('subject')),
            'text' => trim(str_replace("\r\n", "\n", (string) $request->postData('message'))),
            'attach_shifts' => (bool) $request->postData('attach_shifts'),
            'attach_dayplan' => (bool) $request->postData('attach_dayplan'),
        ];
        $recipients = $this->recipients($group, (int) $request->postData('user_id'));

        if (!$this->isEnabled() || !static::hasMailJobs()) {
            $this->addNotification('tagesplan.mail_all.disabled', NotificationType::ERROR);
            return $this->redirect->back();
        }
        if ($payload['subject'] === '' || $payload['text'] === '') {
            $this->addNotification('tagesplan.mail_all.missing', NotificationType::ERROR);
            return $this->redirect->back();
        }
        if (!$recipients->exists()) {
            $this->addNotification('tagesplan.mail_all.no_user', NotificationType::ERROR);
            return $this->redirect->back();
        }

        if ($group != 'one') {
            $job = MailJob::start(MessageJob::class, 'tagesplan.mail_all', $payload['subject'], $payload, $recipients);
            return $this->response->redirectTo(url('/startseite/versand', ['id' => $job->id, 'start' => 1]));
        }

        // A single mail is sent right away
        $user = $recipients->with(['contact', 'personalData'])->first();
        $sent = app()->make(MessageJob::class)->sendTo($user, $payload);
        engelsystem_log(sprintf(
            'Sent the mail "%s" to %s%s: %s',
            $payload['subject'],
            User_Nick_render($user, true),
            ($payload['attach_shifts'] ? ', with shift plan' : '') . ($payload['attach_dayplan'] ? ', with day plan' : ''),
            $sent ? 'sent' : 'failed'
        ));
        if ($sent) {
            $this->addNotification(__('tagesplan.mail_all.success_one', [MessageJob::fullName($user)]));
        } else {
            $this->addNotification(__('tagesplan.mail_all.failed', [1]), NotificationType::ERROR);
        }

        return $this->response->redirectTo(url('/tagesplan'));
    }

    /**
     * Sending in batches and the shift plan PDF come from the plugin Startseite
     */
    public static function hasMailJobs(): bool
    {
        return class_exists(MailJob::class);
    }

    /**
     * @param string $group 'shifts': users with shift entries, 'all': all users, 'one': the user $userId
     */
    protected function recipients(string $group, int $userId = 0): Builder
    {
        return match ($group) {
            'all' => User::query(),
            'one' => User::whereKey($userId),
            default => User::whereHas('shiftEntries'),
        };
    }

    /**
     * Older cores have no isEnabled() and always send
     */
    protected function isEnabled(): bool
    {
        return !method_exists($this->engelsystemMailer, 'isEnabled') || $this->engelsystemMailer->isEnabled();
    }
}
