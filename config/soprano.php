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
    // Source extensions that always get transcoded by extension alone (these
    // formats are losslessly/poorly supported in-browser). Already-compressed
    // formats stream as-is (no transcode, no quality loss from re-encoding).
    "transcode_source_formats" => ["flac", "wav", "aiff", "aif", "ape", "wv"],
    // MP4-family containers (.m4a/.mp4/…) are ambiguous: they hold AAC (plays
    // everywhere, stream as-is) OR ALAC/lossless (Chrome/Firefox can't decode).
    // These get probed by codec and only transcoded when the audio is lossless.
    "transcode_probe_formats" => ["m4a", "mp4", "m4b", "mov"],
    // Audio codecs that warrant transcoding when found inside a probed container.
    "transcode_lossless_codecs" => ["alac", "flac", "pcm_s16le", "pcm_s24le"],
    // Opus target bitrate (VBR). 128k is transparent for most material.
    "transcode_bitrate" => "128k",
    // ffmpeg / ffprobe binaries (must be present in the php container image).
    "ffmpeg_bin" => "ffmpeg",
    "ffprobe_bin" => "ffprobe",
];
