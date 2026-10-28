<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Config\Config;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\User\User;
use Engelsystem\Renderer\Renderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use TypeError;

/**
 * Mails a helper their shifts with the PDF attached
 *
 * The mailer of the core can not attach files, so the mail is built here with its sender and subject prefix
 */
class ShiftPlanMailer
{
    public function __construct(
        protected EngelsystemMailer $engelsystemMailer,
        protected MailerInterface $mailer,
        protected Renderer $view,
        protected Config $config,
        protected LoggerInterface $log
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->engelsystemMailer->isEnabled();
    }

    /**
     * Uses the active locale of the translator
     *
     * @param array[] $days Shift days from HomeController::shiftDays()
     */
    public function send(User $user, array $days): bool
    {
        $to = $user->contact->email ?: $user->email;
        $appName = (string) $this->config->get('app_name');

        $subject = __('startseite.send.subject');
        if ($prefix = $this->optional(fn() => $this->engelsystemMailer->getSubjectPrefix())) {
            $subject = sprintf('[%s] %s', $prefix, $subject);
        }

        try {
            $body = $this->view->render('emails/startseite-shifts', [
                'username' => $user->displayName,
                'count' => array_sum(array_map(fn($day) => count($day['entries']), $days)),
                'hours' => round(array_sum(array_map(fn($day) => $day['hours'], $days)), 2),
            ]);
            $pdf = (new ShiftPdf())->render($user->displayName, $appName, $days);

            $message = (new Email())
                ->to($to)
                ->from(new Address(
                    $this->engelsystemMailer->getFromAddress(),
                    $this->optional(fn() => $this->engelsystemMailer->getFromName())
                ))
                ->subject($subject)
                ->text($body)
                ->attach($pdf, ShiftPdf::filename($user->displayName), 'application/pdf');

            $this->mailer->send($message);
        } catch (Throwable $e) {
            $this->log->error('Startseite: shift plan mail to {to} failed: {type}: {message}', [
                'to' => $to,
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
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
