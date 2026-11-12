<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\User\User;

/**
 * Shift plan as PDF, in the language of the user
 */
class ShiftPlanJob implements MailJobHandler
{
    public function __construct(protected ShiftPlanMailer $mailer)
    {
    }

    public function sendTo(User $user, array $payload): bool
    {
        return $this->mailer->sendPlan($user);
    }
}
