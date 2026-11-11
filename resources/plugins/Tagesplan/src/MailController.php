<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Config\Config;
use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Http\Redirector;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\User\User;
use Engelsystem\Renderer\Renderer;
use Helfersystem\Startseite\ShiftDays;
use Helfersystem\Startseite\ShiftPdf;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use TypeError;

/**
 * Mail with a free subject and text to all helpers or one person, each one greeted with their name,
 * optionally with their shift plan and the day plan as PDF
 *
 * The mailer of the core can not attach files, so the mail is built here with its sender and subject prefix
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
        protected MailerInterface $mailer,
        protected Renderer $view,
        protected Config $config,
        protected Redirector $redirect,
        protected LoggerInterface $log
    ) {
    }

    public function form(): Response
    {
        $users = User::with('personalData')
            ->get()
            ->mapWithKeys(fn(User $user) => [$user->id => static::fullName($user)])
            ->all();
        natcasesort($users);

        return $this->response->withView('tagesplan/mail', [
            'counts' => [
                'shifts' => $this->recipients('shifts')->count(),
                'all' => $this->recipients('all')->count(),
            ],
            'users' => $users,
            'shift_pdf' => static::hasShiftPdf(),
            'enabled' => $this->isEnabled(),
        ]);
    }

    public function send(Request $request): Response
    {
        $group = $request->postData('recipients');
        $group = in_array($group, ['all', 'one'], true) ? $group : 'shifts';
        $subject = trim((string) $request->postData('subject'));
        $text = trim(str_replace("\r\n", "\n", (string) $request->postData('message')));
        $attachShifts = $request->postData('attach_shifts') && static::hasShiftPdf();
        $attachDayPlan = (bool) $request->postData('attach_dayplan');

        $recipients = $this->recipients($group, (int) $request->postData('user_id'))
            ->with(['contact', 'personalData'])
            ->get();

        if (!$this->isEnabled()) {
            $this->addNotification('tagesplan.mail_all.disabled', NotificationType::ERROR);
            return $this->redirect->back();
        }
        if ($subject === '' || $text === '') {
            $this->addNotification('tagesplan.mail_all.missing', NotificationType::ERROR);
            return $this->redirect->back();
        }
        if ($recipients->isEmpty()) {
            $this->addNotification('tagesplan.mail_all.no_user', NotificationType::ERROR);
            return $this->redirect->back();
        }

        // Many recipients can take a while
        set_time_limit(0);

        // The day plans are calculated once for all recipients
        $export = $attachDayPlan ? app()->make(ExportController::class) : null;

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $user) {
            $this->sendTo($user, $subject, $text, $attachShifts, $export) ? $sent++ : $failed++;
        }

        engelsystem_log(sprintf(
            'Sent the mail "%s" to %s%s: %d mails, %d failed',
            $subject,
            $group == 'one' ? User_Nick_render($recipients->first(), true) : 'all helpers (' . $group . ')',
            ($attachShifts ? ', with shift plan' : '') . ($attachDayPlan ? ', with day plan' : ''),
            $sent,
            $failed
        ));
        if ($group == 'one' && $sent) {
            $this->addNotification(__('tagesplan.mail_all.success_one', [static::fullName($recipients->first())]));
        } elseif ($sent) {
            $this->addNotification(__('tagesplan.mail_all.success', [$sent]));
        }
        if ($failed) {
            $this->addNotification(__('tagesplan.mail_all.failed', [$failed]), NotificationType::ERROR);
        }

        return $this->response->redirectTo(url('/tagesplan'));
    }

    protected function sendTo(
        User $user,
        string $subject,
        string $text,
        bool $attachShifts,
        ?ExportController $export
    ): bool {
        $to = $user->contact->email ?: $user->email;
        if ($prefix = $this->optional(fn() => $this->engelsystemMailer->getSubjectPrefix())) {
            $subject = sprintf('[%s] %s', $prefix, $subject);
        }

        try {
            $message = (new Email())
                ->to($to)
                ->from(new Address(
                    $this->engelsystemMailer->getFromAddress(),
                    $this->optional(fn() => $this->engelsystemMailer->getFromName())
                ))
                ->subject($subject)
                ->text($this->view->render('emails/tagesplan-message', [
                    'username' => static::fullName($user),
                    'text' => $text,
                ]));

            // Only for people with shifts
            $days = $attachShifts ? ShiftDays::forUser($user) : [];
            if ($days) {
                $message->attach(
                    (new ShiftPdf())->render($user->displayName, (string) $this->config->get('app_name'), $days),
                    ShiftPdf::filename($user->displayName),
                    'application/pdf'
                );
            }
            if ($export) {
                $message->attach($export->pdfFor($user), $export->filename(), 'application/pdf');
            }

            $this->mailer->send($message);
        } catch (Throwable $e) {
            $this->log->error('Tagesplan: mail to {to} failed: {type}: {message}', [
                'to' => $to,
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
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
     * The shift plan PDF comes from the plugin Startseite
     */
    public static function hasShiftPdf(): bool
    {
        return class_exists(ShiftDays::class) && class_exists(ShiftPdf::class);
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

    /**
     * The getters of the core mailer fail if the value is not configured
     */
    protected function optional(callable $getter): string
    {
        try {
            return (string) $getter();
        } catch (TypeError) {
            return '';
        }
    }
}
