<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class TrackMeta extends Model
{
    protected string $tableName = 'track_meta';

    public function track(): ?Track
    {
        return $this->belongsTo(Track::class);
    }
}
