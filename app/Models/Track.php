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

    /**
     * Album artist (grouping row — "Various Artists" on compilations).
     */
    public function artist(): ?Artist
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Performing artist for this track; same as artist() outside compilations.
     */
    public function trackArtist(): ?Artist
    {
        return $this->belongsTo(Artist::class, 'track_artist_id');
    }

    public function album(): ?Album
    {
        return $this->belongsTo(Album::class);
    }

    public function plays()
    {
        return $this->hasMany(TrackPlay::class, "track_id", "id");
    }

    public function likes()
    {
        return $this->hasMany(TrackLike::class, "track_id", "id");
    }
}
