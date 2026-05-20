<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class Track extends Model
{
    protected string $tableName = 'tracks';

    public function meta(): ?TrackMeta
    {
        return $this->hasOne(TrackMeta::class);
    }

    public function plays()
    {
        return $this->hasMany(TrackPlay::class, "track_id", "id");
    }
}
