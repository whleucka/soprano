<?php

$root = __DIR__ . "/../";

return [
    "music_path" => "/music",
    "covers_path" => $root . "storage/covers",
    "public_covers" => "/covers/",
    // Artist photos backfilled from MusicBrainz → Wikidata/Wikipedia. Stored
    // under the covers dir so they ride the existing public/covers symlink.
    "artist_images_path" => $root . "storage/covers/artists",
    "public_artist_images" => "/covers/artists/",
    // Where soprano:duplicates -i moves removed copies (instead of deleting them).
    "trash_path" => $root . "storage/trash",
];
