<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shows the start page of the plugin instead of the news overview of the core (/news),
 * so the core routes and views stay untouched
 */
class Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('route-request-handler') === 'NewsController@index') {
            $request = $request->withAttribute('route-request-handler', HomeController::class . '@index');
        }

        return $handler->handle($request);
    }
}
