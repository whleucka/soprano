<?php

namespace App\Services\Auth\Client;

use App\Events\Auth\ClientSignedIn;
use App\Events\Auth\ClientSignedOut;
use App\Events\Auth\ClientSignInFailed;
use App\Models\Client;
use App\Services\Auth\AuthService;

class ClientSignInService
{
    public function signIn(string $username, string $password): bool
    {
        $ip = request()->getClientIp();
        $client = Client::where("username", $username)->first();
        $service = container()->get(AuthService::class);

        if ($client && $service->verifyPassword($password, $client->password)) {
            session()->regenerate();
            session()->set("client_uuid", $client->uuid);
            event(new ClientSignedIn($client, $ip));
            return true;
        }

        event(new ClientSignInFailed(
            $username,
            $ip,
            $client ? 'invalid_password' : 'unknown_username',
        ));

        return false;
    }

    public function signOut(): void
    {
        $current = client();
        event(new ClientSignedOut(
            $current?->id,
            $current?->username,
            request()->getClientIp(),
        ));
        session()->destroy();
    }
}
