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
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mail with a free subject and text to all helpers, each one greeted with their name
 */
class MailController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'tagesplan.mail_all',
    ];

    public function __construct(
        protected Response $response,
        protected EngelsystemMailer $mailer,
        protected Redirector $redirect,
        protected LoggerInterface $log
    ) {
    }

    public function form(): Response
    {
        return $this->response->withView('tagesplan/mail', [
            'counts' => [
                'shifts' => $this->recipients('shifts')->count(),
                'all' => $this->recipients('all')->count(),
            ],
            'enabled' => $this->isEnabled(),
        ]);
    }

    public function send(Request $request): Response
    {
        $group = $request->postData('recipients') == 'all' ? 'all' : 'shifts';
        $subject = trim((string) $request->postData('subject'));
        $text = trim(str_replace("\r\n", "\n", (string) $request->postData('message')));

        if (!$this->isEnabled()) {
            $this->addNotification('tagesplan.mail_all.disabled', NotificationType::ERROR);
            return $this->redirect->back();
        }
        if ($subject === '' || $text === '') {
            $this->addNotification('tagesplan.mail_all.missing', NotificationType::ERROR);
            return $this->redirect->back();
        }

        // Many recipients can take a while
        set_time_limit(0);

        $sent = 0;
        $failed = 0;
        foreach ($this->recipients($group)->with(['contact', 'personalData'])->get() as $user) {
            $this->sendTo($user, $subject, $text) ? $sent++ : $failed++;
        }

        engelsystem_log(sprintf('Sent the mail "%s" to all helpers: %d mails, %d failed', $subject, $sent, $failed));
        $this->addNotification(__('tagesplan.mail_all.success', [$sent]));
        if ($failed) {
            $this->addNotification(__('tagesplan.mail_all.failed', [$failed]), NotificationType::ERROR);
        }

        return $this->response->redirectTo(url('/tagesplan'));
    }

    protected function sendTo(User $user, string $subject, string $text): bool
    {
        $to = $user->contact->email ?: $user->email;

        try {
            return $this->mailer->sendView($to, $subject, 'emails/tagesplan-message', [
                'username' => static::fullName($user),
                'text' => $text,
            ]);
        } catch (Throwable $e) {
            $this->log->error('Tagesplan: mail to {to} failed: {type}: {message}', [
                'to' => $to,
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * First and last name for the greeting, the display name (nick) if both are empty
     */
    public static function fullName(User $user): string
    {
        $name = trim(trim((string) $user->personalData?->first_name) . ' ' . trim((string) $user->personalData?->last_name));

        return $name !== '' ? $name : $user->displayName;
    }

    /**
     * @param string $group 'shifts': users with shift entries, 'all': all users
     */
    protected function recipients(string $group): Builder
    {
        return $group == 'all' ? User::query() : User::whereHas('shiftEntries');
    }

    /**
     * Older cores have no isEnabled() and always send
     */
    protected function isEnabled(): bool
    {
        return !method_exists($this->mailer, 'isEnabled') || $this->mailer->isEnabled();
    }
}
