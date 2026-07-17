<?php

// Report-only: lists every track whose performing (track) artist is a
// "Various Artists" placeholder, with a proposed real artist parsed from an
// "Artist - Title" pattern in the title tag. Nothing is written — use the
// output to retag files (keep the albumartist tag as "Various Artists" so
// the compilation stays grouped), then run soprano_refresh_meta.php.

require_once __DIR__ . '/../vendor/autoload.php';

$rows = db()->fetchAll(
    "SELECT t.id, t.pathname, ar.name AS artist, al.title AS album, tm.title AS title
     FROM tracks t
     JOIN artists ar ON ar.id = t.track_artist_id
     JOIN albums al ON al.id = t.album_id
     LEFT JOIN track_meta tm ON tm.track_id = t.id
     WHERE LOWER(TRIM(ar.name)) IN ('va', 'v.a.', 'v/a', 'various', 'various artists')
     ORDER BY al.title, t.pathname"
);

if (!$rows) {
    echo "No tracks with a Various Artists track-artist found.\n";
    exit(0);
}

$parsed   = 0;
$unparsed = 0;

printf("%-8s %-30s %-30s %-30s %s\n", 'ID', 'PROPOSED ARTIST', 'PROPOSED TITLE', 'ALBUM', 'PATH');

foreach ($rows as $row) {
    $title = (string) ($row['title'] ?? '');

    // "Artist - Title" (also matches en/em dashes); first separator wins so
    // artist names containing dashes stay intact more often than not.
    $proposedArtist = '';
    $proposedTitle  = '';
    if (preg_match('/^(.{1,80}?)\s+[-\x{2013}\x{2014}]\s+(.+)$/u', $title, $m)) {
        $proposedArtist = trim($m[1]);
        $proposedTitle  = trim($m[2]);
        $parsed++;
    } else {
        $proposedArtist = '???';
        $proposedTitle  = $title;
        $unparsed++;
    }

    printf(
        "%-8s %-30s %-30s %-30s %s\n",
        $row['id'],
        mb_substr($proposedArtist, 0, 29),
        mb_substr($proposedTitle, 0, 29),
        mb_substr((string) $row['album'], 0, 29),
        $row['pathname'],
    );
}

printf(
    "\n%d VA tracks: %d with a parseable \"Artist - Title\" pattern, %d needing manual/MusicBrainz lookup.\n",
    count($rows),
    $parsed,
    $unparsed,
);
