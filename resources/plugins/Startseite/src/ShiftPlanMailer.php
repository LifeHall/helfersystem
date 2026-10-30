<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Config\Config;
use Engelsystem\Helpers\Translation\Translator;
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
        protected LoggerInterface $log,
        protected Translator $translator
    ) {
    }

    /**
     * Older cores have no isEnabled() and always send
     */
    public function isEnabled(): bool
    {
        return !method_exists($this->engelsystemMailer, 'isEnabled') || $this->engelsystemMailer->isEnabled();
    }

    /**
     * Mails the current shifts of the user in their language
     *
     * @param string $template Mail text, gets username, count, hours and $data
     */
    public function sendPlan(
        User $user,
        string $template = 'emails/startseite-shifts',
        string $subject = 'startseite.send.subject',
        array $data = []
    ): bool {
        $activeLocale = $this->translator->getLocale();
        $locale = in_array($user->settings?->language, $this->translator->getLocales())
            ? $user->settings->language
            : $activeLocale;
        $this->translator->setLocale($locale);

        try {
            return $this->send($user, ShiftDays::forUser($user, $locale), $template, $subject, $data);
        } finally {
            $this->translator->setLocale($activeLocale);
        }
    }

    /**
     * Uses the active locale of the translator
     *
     * @param array[] $days Shift days from ShiftDays::forUser()
     */
    public function send(
        User $user,
        array $days,
        string $template = 'emails/startseite-shifts',
        string $subject = 'startseite.send.subject',
        array $data = []
    ): bool {
        $to = $user->contact->email ?: $user->email;
        $appName = (string) $this->config->get('app_name');

        $subject = __($subject);
        if ($prefix = $this->optional(fn() => $this->engelsystemMailer->getSubjectPrefix())) {
            $subject = sprintf('[%s] %s', $prefix, $subject);
        }

        try {
            $body = $this->view->render($template, [
                'username' => $user->displayName,
                'count' => array_sum(array_map(fn($day) => count($day['entries']), $days)),
                'hours' => round(array_sum(array_map(fn($day) => $day['hours'], $days)), 2),
            ] + $data);
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
