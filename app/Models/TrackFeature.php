<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class TrackFeature extends Model
{
    protected string $tableName = 'track_features';

    public function track(): ?Track
    {
        return $this->belongsTo(Track::class);
    }
}
