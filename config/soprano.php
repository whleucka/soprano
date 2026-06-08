<?php

$root = __DIR__ . "/../";

return [
    "music_path" => "/music",
    "covers_path" => $root . "storage/covers",
    "public_covers" => "/covers/",
    // Where soprano:duplicates -i moves removed copies (instead of deleting them).
    "trash_path" => $root . "storage/trash",
];
