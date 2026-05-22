<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class Album extends Model
{
    protected string $tableName = 'albums';

    public function tracks(): array
    {
        return $this->hasMany(Track::class);
    }

    public function artist(): ?Artist
    {
        return $this->belongsTo(Artist::class);
    }
}
