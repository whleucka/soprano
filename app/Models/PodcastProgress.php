<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class PodcastProgress extends Model
{
    protected string $tableName = 'podcast_progress';

    public function client(): ?Client
    {
        return $this->belongsTo(Client::class);
    }
}
