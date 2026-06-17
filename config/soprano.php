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

    // Transcoding: lossless/large source files are transcoded once to Opus and
    // cached here (keyed by track hash), then served statically via X-Accel.
    // Keeps bandwidth low and sidesteps flaky in-browser FLAC streaming.
    "transcode_path" => $root . "storage/transcode",
    // Source extensions that get transcoded. Already-compressed formats stream
    // as-is (no transcode, no quality loss from re-encoding).
    "transcode_source_formats" => ["flac", "wav", "aiff", "aif", "ape", "wv", "alac"],
    // Opus target bitrate (VBR). 128k is transparent for most material.
    "transcode_bitrate" => "128k",
    // ffmpeg binary (must be present in the php container image).
    "ffmpeg_bin" => "ffmpeg",
];
