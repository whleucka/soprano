<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class TrackMeta extends Model
{
    protected string $tableName = 'track_meta';

    public string $cover {
        get {
            return isset($this->cover) 
                ? $this->cover : 
                '/images/no-album-art.png';
        }
    }

    public function track(): ?Track
    {
        return $this->belongsTo(Track::class);
    }
}
