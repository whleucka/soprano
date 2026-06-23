<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class Podcast extends Model
{
    protected string $tableName = 'podcasts';

    public function likes()
    {
        return $this->hasMany(PodcastLike::class, "podcast_id", "id");
    }
}
