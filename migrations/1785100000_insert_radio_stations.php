<?php

use Echo\Framework\Database\MigrationInterface;

return new class implements MigrationInterface
{
    private array $stations = [
        [
            "hash"     => "a01e2f18bcc83d4dc830a9527af88dd7",
            "cover"    => "https://www.seekyoursounds.com/wp-content/uploads/2024/06/Seekr-RadioCover-SONIC-1029-300x300.png",
            "name"     => "Sonic 102.9",
            "country"  => "Canada",
            "province" => "AB",
            "city"     => "Edmonton",
            "src"      => "https://rogers-hls.leanstream.co/rogers/edm1029.stream/48k/playlist.m3u8",
        ],
        [
            "hash"     => "07e0de2af8eba648da043ac035bc6953",
            "cover"    => "https://www.seekyoursounds.com/wp-content/uploads/2024/06/Seekr-RadioCover-JACK-1031-300x300.png",
            "name"     => "Jack 103.1",
            "country"  => "Canada",
            "province" => "BC",
            "city"     => "Victoria",
            "src"      => "https://rogers-hls.leanstream.co/rogers/vic1031.stream/48k/playlist.m3u8",
        ],
        [
            "hash"     => "f2c9239aea5de69909bc45db35e1aff7",
            "cover"    => "https://www.seekyoursounds.com/wp-content/uploads/2024/06/Seekr-RadioCover-ROCK-1067-300x300.png",
            "name"     => "106.7 ROCK",
            "country"  => "Canada",
            "province" => "AB",
            "city"     => "Lethbridge",
            "src"      => "https://rogers-hls.leanstream.co/rogers/let1067.stream/48k/playlist.m3u8",
        ],
        [
            "hash"     => "1740769c8ba8ce318a19dd443ddecbb7",
            "cover"    => "https://www.seekyoursounds.com/wp-content/uploads/2024/06/Seekr-RadioCover-KISS-1077-1.png",
            "name"     => "KiSS 107.7",
            "country"  => "Canada",
            "province" => "AB",
            "city"     => "Lethbridge",
            "src"      => "https://rogers-hls.leanstream.co/rogers/let1077.stream/48k/playlist.m3u8",
        ],
    ];

    public function up(): string
    {
        $rows = array_map(
            fn (array $s) => sprintf(
                "('%s', '%s', '%s', '%s', '%s', '%s', '%s')",
                $s["hash"],
                addslashes($s["cover"]),
                addslashes($s["name"]),
                $s["country"],
                $s["province"],
                addslashes($s["city"]),
                addslashes($s["src"]),
            ),
            $this->stations
        );

        return "INSERT INTO radio_stations (hash, cover, name, country, province, city, src) VALUES "
            . implode(", ", $rows);
    }

    public function down(): string
    {
        $hashes = implode("', '", array_column($this->stations, "hash"));
        return "DELETE FROM radio_stations WHERE hash IN ('$hashes')";
    }
};
