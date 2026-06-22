<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class RadioStationLike extends Model
{
    protected string $tableName = 'radio_station_likes';

    public function radioStation(): ?RadioStation
    {
        return $this->belongsTo(RadioStation::class);
    }

    public function client(): ?Client
    {
        return $this->belongsTo(Client::class);
    }
}
