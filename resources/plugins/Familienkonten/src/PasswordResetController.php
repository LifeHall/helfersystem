<?php

declare(strict_types=1);

namespace Helfersystem\Familienkonten;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Mail\EngelsystemMailer;
use Engelsystem\Models\User\PasswordReset;
use Engelsystem\Models\User\User;
use Psr\Log\LoggerInterface;

/**
 * Password reset for a mail address with several accounts: one mail per account, with the name of the account
 */
class PasswordResetController extends BaseController
{
    protected array $permissions = [
        'postReset' => 'login',
    ];

    public function __construct(
        protected Response $response,
        protected EngelsystemMailer $mail,
        protected LoggerInterface $log
    ) {
    }

    public function postReset(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
        ]);

        /** @var User $user */
        foreach (User::whereEmail($data['email'])->get() as $user) {
            $reset = (new PasswordReset())->findOrNew($user->id);
            $reset->user_id = $user->id;
            $reset->token = bin2hex(random_bytes(16));
            $reset->created_at = Carbon::now();
            $reset->save();

            $this->log->info(
                sprintf('Password recovery for %s (%u), shared mail address', $user->name, $user->id),
                ['user' => $user->toJson()]
            );

            $this->mail->sendViewTranslated(
                $user,
                'Password recovery',
                'emails/familienkonten-password-reset',
                ['username' => $user->displayName, 'name' => $user->name, 'reset' => $reset]
            );
        }

        return $this->response->withView('pages/password/reset-success', ['type' => 'email']);
    }
}
