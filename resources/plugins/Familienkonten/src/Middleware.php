<?php

declare(strict_types=1);

namespace Helfersystem\Familienkonten;

use Engelsystem\Events\EventDispatcher;
use Engelsystem\Http\Request;
use Engelsystem\Models\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Accounts with a shared mail address, without changing the core:
 * registration, login with the mail address and password reset
 */
class Middleware implements MiddlewareInterface
{
    public function __construct(protected EventDispatcher $events)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request instanceof Request) {
            $request = match ($request->getAttribute('route-request-handler')) {
                'RegistrationController@save' => $this->register($request),
                'AuthController@postLogin' => $this->login($request),
                'PasswordResetController@postReset' => $this->passwordReset($request),
                default => $request,
            };
        }

        return $handler->handle($request);
    }

    /**
     * The core refuses a mail address that is already used, so the registration gets a unique placeholder,
     * which is replaced by the real address before anything else happens with the new user (e.g. welcome mail)
     */
    protected function register(Request $request): Request
    {
        $email = trim((string) $request->postData('email'));
        if (!$email || !User::whereEmail($email)->exists()) {
            return $request;
        }

        $placeholder = sprintf('familienkonto-%s@helfersystem.invalid', bin2hex(random_bytes(8)));
        $this->prependListener('user.created', function (User $user) use ($placeholder, $email): void {
            if ($user->email === $placeholder) {
                $user->email = $email;
                $user->save();
            }
        });

        return $request->withParsedBody(['email' => $placeholder] + $request->request->all());
    }

    /**
     * The core takes the first account with the mail address, so the login is changed to the name
     * of the account whose password matches
     */
    protected function login(Request $request): Request
    {
        $login = trim((string) $request->postData('login'));
        $password = (string) $request->postData('password');
        $accounts = $login ? User::whereEmail($login)->get() : collect();
        if ($accounts->count() < 2 || User::whereName($login)->exists()) {
            return $request;
        }

        $user = $accounts->first(fn(User $account) => password_verify($password, $account->password));
        if (!$user) {
            return $request;
        }

        return $request->withParsedBody(['login' => $user->name] + $request->request->all());
    }

    /**
     * The core only resets the first account with the mail address, the plugin sends one mail per account
     */
    protected function passwordReset(Request $request): Request
    {
        $email = trim((string) $request->postData('email'));
        if (!$email || User::whereEmail($email)->count() < 2) {
            return $request;
        }

        return $request->withAttribute('route-request-handler', PasswordResetController::class . '@postReset');
    }

    /**
     * Runs the listener before the ones of the core and the other plugins
     */
    protected function prependListener(string $event, callable $listener): void
    {
        $listeners = (fn() => $this->listeners[$event] ?? [])->call($this->events);

        $this->events->forget($event);
        foreach ([$listener, ...$listeners] as $handler) {
            $this->events->listen($event, $handler);
        }
    }
}
