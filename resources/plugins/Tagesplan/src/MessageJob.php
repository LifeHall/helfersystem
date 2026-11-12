<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Config\Config;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\User\User;
use Engelsystem\Renderer\Renderer;
use Helfersystem\Startseite\MailJobHandler;
use Helfersystem\Startseite\ShiftDays;
use Helfersystem\Startseite\ShiftPdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use TypeError;

/**
 * Mail with free subject and text, greeted with the name, optionally with the shift plan and the day plan as PDF
 *
 * Payload: subject, text, attach_shifts, attach_dayplan.
 * The mailer of the core can not attach files, so the mail is built here with its sender and subject prefix
 */
class MessageJob implements MailJobHandler
{
    // Calculates the day plans once for all recipients of a batch
    protected ?ExportController $export = null;

    public function __construct(
        protected EngelsystemMailer $engelsystemMailer,
        protected MailerInterface $mailer,
        protected Renderer $view,
        protected Config $config,
        protected LoggerInterface $log
    ) {
    }

    public function sendTo(User $user, array $payload): bool
    {
        $to = $user->contact->email ?: $user->email;
        $subject = (string) $payload['subject'];
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
                    'text' => (string) $payload['text'],
                ]));

            // Only for people with shifts
            $days = !empty($payload['attach_shifts']) ? ShiftDays::forUser($user) : [];
            if ($days) {
                $message->attach(
                    (new ShiftPdf())->render($user->displayName, (string) $this->config->get('app_name'), $days),
                    ShiftPdf::filename($user->displayName),
                    'application/pdf'
                );
            }
            if (!empty($payload['attach_dayplan'])) {
                $this->export ??= app()->make(ExportController::class);
                $message->attach($this->export->pdfFor($user), $this->export->filename(), 'application/pdf');
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
