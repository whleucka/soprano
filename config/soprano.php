<?php

$root = __DIR__ . "/../";

return [
    "music_path" => env("MUSIC_DIR"),
    "covers_path" => $root . "storage/covers",
    "public_covers" => "/covers/",
];
