<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class Playlist extends Model
{
    protected string $tableName = 'playlists';

    public function client(): ?Client
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(PlaylistTrack::class, "playlist_id", "id");
    }
}
