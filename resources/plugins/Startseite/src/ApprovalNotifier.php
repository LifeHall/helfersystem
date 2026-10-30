<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\User\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails everybody who can confirm a membership request for a helper type
 */
class ApprovalNotifier
{
    public function __construct(
        protected EngelsystemMailer $mailer,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Recipients: users allowed to edit helper types and supporters of the type
     */
    public function notify(User $requester, AngelType $type): void
    {
        // admin_user_angeltypes: name before the rename in the core (2026-10)
        $editors = User::whereHas(
            'groups.privileges',
            fn($query) => $query->whereIn('name', ['user.angeltypes.edit', 'admin_user_angeltypes'])
        )->get();
        $supporters = $type->userAngelTypes()->wherePivot('supporter', true)->get();

        $recipients = $editors->merge($supporters)
            ->unique('id')
            ->reject(fn(User $user) => $user->id == $requester->id);

        foreach ($recipients as $recipient) {
            try {
                $this->mailer->sendViewTranslated(
                    $recipient,
                    'startseite.mail.subject',
                    'emails/startseite-approval',
                    [
                        // The first two values fill the placeholders of the subject
                        'requester' => $requester->displayName,
                        'angeltype_name' => $type->name,
                        'username' => $recipient->displayName,
                        'angeltype' => $type,
                    ]
                );
            } catch (Throwable $e) {
                $this->log->error('Startseite: approval mail to {user} failed: {error}', [
                    'user' => $recipient->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
