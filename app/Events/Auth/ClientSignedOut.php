<?php

namespace App\Events\Auth;

use Echo\Framework\Event\Event;

class ClientSignedOut extends Event
{
    public function __construct(
        public readonly ?int $clientId,
        public readonly ?string $username,
        public readonly string $ip,
    ) {}
}
