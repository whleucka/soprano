<?php

namespace App\Services\Auth\Client;

use App\Events\Auth\ClientRegistered;
use App\Models\Client;
use App\Services\Auth\AuthService;

class ClientRegisterService
{
    public function register(string $username, string $password): bool
    {
        $service = container()->get(AuthService::class);
        $ip = request()->getClientIp();

        $client = Client::create([
            "username" => $username,
            "password" => $service->hashPassword($password),
        ]);

        if ($client) {
            session()->regenerate();
            session()->set("client_uuid", $client->uuid);
            event(new ClientRegistered($client, $ip));
            return true;
        }

        logger()->channel('auth')->warning('Client registration failed', [
            'username' => $username,
            'ip' => $ip,
        ]);

        return false;
    }
}
