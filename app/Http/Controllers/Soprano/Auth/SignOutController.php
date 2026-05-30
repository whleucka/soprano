<?php

namespace App\Http\Controllers\Soprano\Auth;

use App\Services\Auth\Client\ClientSignInService;
use Echo\Framework\Http\Response;
use Echo\Framework\Routing\Route\Post;

class SignOutController extends AuthController
{
    public function __construct(private ClientSignInService $service)
    {
    }

    #[Post("/sign-out", "sign-out.post", ["client"])]
    public function post(): Response
    {
        $this->service->signOut();
        $path = uri("client.auth.sign-in.index");
        return redirect($path)->withFlash("success", "You are now signed out");
    }
}
