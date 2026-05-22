<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class Artist extends Model
{
    protected string $tableName = 'artists';

    public function tracks(): array
    {
        return $this->hasMany(Track::class);
    }
}
