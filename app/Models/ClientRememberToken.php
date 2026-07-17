<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class ClientRememberToken extends Model
{
    protected string $tableName = 'client_remember_tokens';
}
