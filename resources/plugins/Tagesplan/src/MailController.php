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
            // Entries after an error, so they need not be typed again
            'form' => $this->pullForm(),
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
            return $this->back($request);
        }

        // People who already got the mail, e.g. from an aborted sending
        $exclude = $group != 'one' ? (string) $request->postData('exclude') : '';
        [$excluded, $unknown] = $this->excluded($exclude);
        if ($unknown) {
            $this->addNotification(
                __('tagesplan.mail_all.exclude.unknown', [implode(', ', $unknown)]),
                NotificationType::ERROR
            );
            return $this->back($request);
        }
        if ($excluded) {
            $recipients->whereNotIn('users.id', array_keys($excluded));
        }

        if (!$recipients->exists()) {
            $this->addNotification('tagesplan.mail_all.no_user', NotificationType::ERROR);
            return $this->back($request);
        }

        if ($group != 'one') {
            if ($excluded) {
                engelsystem_log(sprintf(
                    'Mail "%s": %d people excluded: %s',
                    $payload['subject'],
                    count($excluded),
                    implode(', ', $excluded)
                ));
            }
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
     * People of the exclude list: mail addresses (also "Name <address>") or names, one per line or separated by , or ;
     *
     * @return array [string[] $excluded [user_id => name], string[] $unknown Entries without (exactly) one person]
     */
    protected function excluded(string $list): array
    {
        $entries = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', $list))));
        if (!$entries) {
            return [[], []];
        }

        // All addresses and names of every user, lower case
        $index = [];
        foreach (User::with(['contact', 'personalData'])->get() as $user) {
            $keys = [$user->email, $user->contact->email, $user->name, $user->displayName, MessageJob::fullName($user)];
            foreach (array_unique(array_filter(array_map(fn($key) => mb_strtolower(trim((string) $key)), $keys))) as $key) {
                $index[$key][$user->id] = $user;
            }
        }

        $excluded = [];
        $unknown = [];
        foreach ($entries as $entry) {
            // "Name <address>" as copied from a mail program: the address counts
            $key = preg_match('/[^\s<>"]+@[^\s<>"]+/', $entry, $match) ? $match[0] : $entry;
            $users = $index[mb_strtolower($key)] ?? [];
            if (count($users) != 1) {
                $unknown[] = $entry;
                continue;
            }
            $user = reset($users);
            $excluded[$user->id] = User_Nick_render($user, true);
        }

        return [$excluded, $unknown];
    }

    /**
     * Back to the form with the entries
     */
    protected function back(Request $request): Response
    {
        session()->set('tagesplan_mail_form', [
            'recipients' => $request->postData('recipients'),
            'user_id' => $request->postData('user_id'),
            'exclude' => (string) $request->postData('exclude'),
            'subject' => (string) $request->postData('subject'),
            'message' => (string) $request->postData('message'),
            'attach_shifts' => (bool) $request->postData('attach_shifts'),
            'attach_dayplan' => (bool) $request->postData('attach_dayplan'),
        ]);

        return $this->response->redirectTo(url('/tagesplan/rundmail'));
    }

    protected function pullForm(): array
    {
        $form = (array) session()->get('tagesplan_mail_form', []);
        session()->remove('tagesplan_mail_form');

        return $form;
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
