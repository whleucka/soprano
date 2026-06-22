<?php

namespace App\Models;

use Echo\Framework\Database\Model;

class RadioStation extends Model
{
    protected string $tableName = 'radio_stations';

    public function likes()
    {
        return $this->hasMany(RadioStationLike::class, "radio_station_id", "id");
    }
}
