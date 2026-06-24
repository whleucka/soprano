<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class PlaylistTrack extends Model
{
    protected string $tableName = 'playlist_tracks';

    public function playlist(): ?Playlist
    {
        return $this->belongsTo(Playlist::class);
    }

    public function track(): ?Track
    {
        return $this->belongsTo(Track::class);
    }
}
