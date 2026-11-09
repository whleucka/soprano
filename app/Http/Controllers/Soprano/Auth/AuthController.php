<?php

namespace App\Http\Controllers\Soprano\Auth;

use App\Http\Middleware\ClientAuth;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;

#[Group(namePrefix: 'client.auth')]
class AuthController extends Controller
{
    /**
     * Where to send a client who just signed in or registered: the page that
     * bounced them to the sign-in form, if there was one, else home. Read
     * once and forgotten.
     */
    protected function intendedPath(): string
    {
        $path = session()->get(ClientAuth::INTENDED);
        session()->delete(ClientAuth::INTENDED);

        return is_string($path) && self::isLocalPath($path)
            ? $path
            : config("security.client_authenticated_route");
    }

    /**
     * Only a same-site path. "//host" and "/\host" are protocol-relative
     * to a browser, which would make this an open redirect.
     */
    public static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, "/")
            && !str_starts_with($path, "//")
            && !str_starts_with($path, "/\\");
    }
}
