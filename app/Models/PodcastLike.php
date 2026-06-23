<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class PodcastLike extends Model
{
    protected string $tableName = 'podcast_likes';

    public function podcast(): ?Podcast
    {
        return $this->belongsTo(Podcast::class);
    }

    public function client(): ?Client
    {
        return $this->belongsTo(Client::class);
    }
}
