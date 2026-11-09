<?php

namespace App\Http\Middleware;

use App\Services\Auth\Client\RememberTokenService;
use Closure;
use Echo\Framework\Http\Response as HttpResponse;
use Echo\Framework\Http\RequestInterface;
use Echo\Framework\Http\ResponseInterface;
use Echo\Framework\Http\MiddlewareInterface;
use Echo\Framework\Session\Flash;

class ClientAuth implements MiddlewareInterface
{
    public const INTENDED = "client_intended";

    public function handle(RequestInterface $request, Closure $next): ResponseInterface
    {
        $route = $request->getAttribute("route");
        $middleware = $route["middleware"] ?? [];

        if (!in_array('client', $middleware, true)) {
            return $next($request);
        }

        // Session expired (or new browser session) but the device has a
        // remember-me cookie — silently re-establish the session.
        if (!session()->has('client_uuid') && isset($_COOKIE[RememberTokenService::COOKIE])) {
            container()->get(RememberTokenService::class)->attempt();
        }

        $client = client();
        if ($client) {
            $request->setAttribute('client', $client);
            return $next($request);
        }

        // Remember where a full-page visit was headed, so signing in lands
        // there instead of on the home page. This is what lets a playlist
        // invite link survive a trip through the sign-in form. Path only:
        // see AuthController::intendedPath() for the other half.
        if ($request->getMethod() === "GET" && !$request->isHTMX()) {
            session()->set(self::INTENDED, $request->getUri());
        }

        Flash::add("warning", "Please sign in to continue.");
        $loginRoute = uri("client.auth.sign-in.index");

        if ($request->isHTMX()) {
            $res = new HttpResponse('', 200);
            $res->setHeader('HX-Redirect', $loginRoute);
            return $res;
        }

        $res = new HttpResponse('', 302);
        $res->setHeader('Location', $loginRoute);
        return $res;
    }
}
