<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Auth\AuthService;
use App\Services\Auth\Client\RememberTokenService;
use App\Services\Soprano\ProfileService;
use App\Services\Soprano\WrappedService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Http\Response;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\{Get, Post};
use Echo\Framework\Session\Flash;

#[Group(middleware: ["client"])]
class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profile,
        private AuthService $auth,
        private RememberTokenService $remember,
        private WrappedService $wrapped,
    ) {}

    #[Get("/profile", "profile.index")]
    public function index(): string
    {
        return $this->render("profile/index.html.twig", [
            "client"   => client(),
            "stats"    => $this->profile->getStats(),
            "settings" => $this->profile->getSettings(),
            // Only surface the Wrapped banner during its season (Dec 15 – Jan
            // 15), matching the home page — the page stays reachable directly.
            "wrapped_season" => $this->wrapped->isSeason(),
            "wrapped_year"   => $this->wrapped->seasonYear(),
        ]);
    }

    #[Post("/profile/settings", "profile.settings", ["max_requests" => 30, "decay_seconds" => 60])]
    public function settings(): string
    {
        $data    = (array) request()->request->data();
        $shuffle = !empty($data["default_shuffle"]);
        $repeat  = (string) ($data["default_repeat"] ?? "off");

        $this->profile->saveSettings($shuffle, $repeat);
        Flash::add("success", "Player settings saved.");

        return $this->index();
    }

    #[Post("/profile/delete", "profile.delete", ["max_requests" => 5, "decay_seconds" => 60])]
    public function delete(): string|Response
    {
        $valid = $this->validate([
            "password" => ["required"],
        ]);

        if ($valid) {
            $client = client();
            if ($client && $this->auth->verifyPassword($valid->password, $client->password)) {
                // Revoke this device's remember cookie first, then drop the
                // row — foreign keys cascade the client's plays, likes,
                // playlists, podcast progress and remaining tokens.
                $this->remember->forget();
                $client->delete();
                session()->destroy();

                return redirect(uri("client.auth.sign-in.index"))
                    ->withFlash("success", "Your account has been permanently deleted.");
            }
            Flash::add("danger", "Incorrect password — your account was not deleted.");
        }

        return $this->index();
    }
}
