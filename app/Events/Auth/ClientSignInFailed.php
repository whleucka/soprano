<?php

namespace App\Events\Auth;

use Echo\Framework\Event\Event;

class ClientSignInFailed extends Event
{
    public function __construct(
        public readonly string $username,
        public readonly string $ip,
        public readonly string $reason,
    ) {}
}
