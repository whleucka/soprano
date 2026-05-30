<?php

namespace App\Http\Controllers\Soprano\Auth;

use App\Services\Auth\Client\ClientSignInService;
use Echo\Framework\Http\Response;
use Echo\Framework\Routing\Route\{Get, Post};
use Echo\Framework\Session\Flash;

class SignInController extends AuthController
{
    public function __construct(private ClientSignInService $service)
    {
    }

    #[Get("/sign-in", "sign-in.index")]
    public function index(): string
    {
        return $this->render("soprano/auth/sign-in/index.html.twig", [
            "register_enabled" => config("security.client_register_enabled"),
        ]);
    }

    #[Post("/sign-in", "sign-in.post", ["max_requests" => 10, "decay_seconds" => 60])]
    public function post(): string|Response
    {
        $valid = $this->validate([
            "username" => ["required"],
            "password" => ["required"],
        ]);
        if ($valid) {
            $success = $this->service->signIn($valid->username, $valid->password);
            if ($success) {
                $path = config("security.client_authenticated_route");
                return redirect($path)->withFlash("success", "Welcome back, " . client()->username . ".");
            } else {
                Flash::add("warning", "Invalid username and/or password");
            }
        }
        return $this->index();
    }
}
