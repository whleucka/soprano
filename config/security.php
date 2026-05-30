<?php

return [
    "authenticated_route" => uri("dashboard.admin.index"),
    "register_enabled" => env("AUTH_REGISTER_ENABLED", false),
    "client_authenticated_route" => "/home",
    "client_register_enabled" => env("CLIENT_REGISTER_ENABLED", true),
    "trusted_proxies" => array_filter(explode(',', env('TRUSTED_PROXIES') ?? '')),
    "whitelist" => [
    ],
    "blacklist" => [
    ],
    "max_requests" => 200,
    "max_requests_htmx" => 1000,  // Higher limit for HTMX (authenticated interactions)
    "decay_seconds" => 60,
    "password_algorithm" => PASSWORD_ARGON2I,
];
