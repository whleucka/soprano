<?php

namespace App\Services\Auth;

use App\Events\Auth\SignInFailed;
use App\Events\Auth\UserSignedIn;
use App\Events\Auth\UserSignedOut;
use App\Models\User;

class SignInService
{
    public function signIn(string $email_address, string $password): bool
    {
        $ip = request()->getClientIp();
        $user = User::where("email", $email_address)->first();
        $service = container()->get(AuthService::class);

        if ($user && $service->verifyPassword($password, $user->password)) {
            session()->regenerate();
            session()->set("user_uuid", $user->uuid);
            session()->set("dark_mode", $user->theme === "dark");
            event(new UserSignedIn($user, $ip));
            return true;
        }

        event(new SignInFailed(
            $email_address,
            $ip,
            $user ? 'invalid_password' : 'unknown_email',
        ));

        return false;
    }

    public function signOut(): void
    {
        $currentUser = user();
        event(new UserSignedOut(
            $currentUser?->id,
            $currentUser?->email,
            request()->getClientIp(),
        ));

        // Only the admin user's own keys. The backend moved from its own
        // subdomain to /admin on the main host, so it now shares one PHP
        // session with the player — destroy() here would sign the listener
        // out and wipe their playback queue (App\State\Soprano lives in
        // $_SESSION too). regenerate() carries the remaining data to a new id.
        session()->delete("user_uuid");
        session()->delete("dark_mode");
        session()->regenerate();
    }
}
