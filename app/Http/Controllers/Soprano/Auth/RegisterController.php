<?php

namespace App\Http\Controllers\Soprano\Auth;

use App\Services\Auth\Client\ClientRegisterService;
use Echo\Framework\Http\Response;
use Echo\Framework\Routing\Route\{Get, Post};
use Echo\Framework\Session\Flash;

class RegisterController extends AuthController
{
    public function __construct(private ClientRegisterService $service)
    {
        if (!config("security.client_register_enabled")) {
            $this->permissionDenied();
        }
    }

    #[Get("/register", "register.index")]
    public function index(): string
    {
        return $this->render("soprano/auth/register/index.html.twig");
    }

    #[Post("/register", "register.post", ["max_requests" => 10, "decay_seconds" => 60])]
    public function post(): string|Response
    {
        $this->setValidationMessage("password.min_length", "Must be at least 10 characters");
        $this->setValidationMessage("password.regex", "Must contain 1 upper case, 1 digit, 1 symbol");
        $this->setValidationMessage("password_match.match", "Password does not match");
        $valid = $this->validate([
            "username" => ["required", "min_length:3", "unique:clients"],
            "password" => ["required", "min_length:10", "regex:^(?=.*[A-Z])(?=.*\W)(?=.*\d).+$"],
            "password_match" => ["required", "match:password"],
        ]);
        if ($valid) {
            $success = $this->service->register($valid->username, $valid->password);
            if ($success) {
                $path = config("security.client_authenticated_route");
                return redirect($path)->withFlash("success", "Welcome, " . client()->username . "!");
            } else {
                Flash::add("warning", "Failed to register new account");
            }
        }
        return $this->index();
    }
}
