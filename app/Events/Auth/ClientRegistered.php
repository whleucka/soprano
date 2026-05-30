<?php

namespace App\Events\Auth;

use App\Models\Client;
use Echo\Framework\Event\Event;

class ClientRegistered extends Event
{
    public function __construct(
        public readonly Client $client,
        public readonly string $ip,
    ) {}
}
