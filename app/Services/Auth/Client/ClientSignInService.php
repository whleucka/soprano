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
            container()->get(RememberTokenService::class)->issue($client);
            $this->seedPlayerDefaults($client);
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

    /**
     * Seed the fresh session's queue with the client's saved shuffle/repeat
     * defaults so a new listening session starts how they prefer.
     */
    private function seedPlayerDefaults(Client $client): void
    {
        container()->get(\App\Services\Soprano\PlaylistService::class)->applyDefaults(
            (bool) ($client->default_shuffle ?? false),
            $client->default_repeat ?? 'off',
        );
    }

    public function signOut(): void
    {
        $current = client();
        event(new ClientSignedOut(
            $current?->id,
            $current?->username,
            request()->getClientIp(),
        ));
        container()->get(RememberTokenService::class)->forget();
        session()->destroy();
    }
}
