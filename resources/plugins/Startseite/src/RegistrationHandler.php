<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\AngelType;
use Engelsystem\Models\User\User;

/**
 * Registration (event user.created): helper types chosen there that require a confirmation
 * are requests, so the approvers get a mail like for a request from the guided sign up
 */
class RegistrationHandler
{
    public function __construct(protected ApprovalNotifier $notifier)
    {
    }

    public function handle(string $event, User $user): void
    {
        $user->userAngelTypes()
            ->where('restricted', true)
            ->wherePivotNull('confirm_user_id')
            ->get()
            ->each(fn(AngelType $type) => $this->notifier->notify($user, $type));
    }
}
