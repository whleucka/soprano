<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class TrackPlay extends Model
{
    protected string $tableName = 'track_play';

    public function track(): ?Track
    {
        return $this->belongsTo(Track::class);
    }

    public function client(): ?Track
    {
        return $this->belongsTo(Client::class);
    }
}
